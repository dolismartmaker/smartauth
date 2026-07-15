<?php

/**
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 */

namespace SmartAuth\Api;

/**
 * Adapts the divergent create()/update()/delete() signatures of the various
 * Dolibarr CommonObject subclasses so a generic controller can drive any of
 * them without per-class branching.
 *
 * Dolibarr never settled on one CRUD signature:
 *   - Societe::create(User $user, ...)        Societe::update($id, $user, ...)   Societe::delete($id, User $user, ...)
 *   - Product::create(User $user, ...)        Product::update($id, $user, ...)   Product::delete(User $user, ...)
 *   - Contact::create(User $user, ...)        Contact::update($id, $user, ...)   Contact::delete($notrigger)
 *   - Categorie::create(User $user, ...)      Categorie::update($user, ...)      Categorie::delete($user, ...)
 *
 * The reflection here mirrors the proven update-dispatch already used inside
 * SyncController::callUpdateMethod(), generalised to create and delete.
 */
class CrudInvoker
{
    /**
     * Persist a NEW object. create() is uniformly ($user, ...) across the core
     * classes, so no reflection is needed for the happy path; we still guard on
     * method existence.
     *
     * @param  object $object  Dolibarr object with its properties already set.
     * @param  \User  $user    Authenticated user.
     * @return int             Dolibarr create() result (>0 = new id).
     */
    public static function create($object, $user)
    {
        return $object->create($user);
    }

    /**
     * Update an existing object, adapting to id-first vs user-first signatures.
     *
     * @param  object $object  Fetched Dolibarr object with mutated properties.
     * @param  \User  $user    Authenticated user.
     * @return int             Dolibarr update() result (>0 = ok).
     */
    public static function update($object, $user)
    {
        $reflection = new \ReflectionMethod($object, 'update');
        $params = $reflection->getParameters();

        if (empty($params)) {
            return $object->update();
        }

        $firstParam = $params[0];
        $firstParamName = $firstParam->getName();
        $firstParamType = $firstParam->getType();

        $isIdFirst = ($firstParamName === 'id')
            || ($firstParamType instanceof \ReflectionNamedType
                && in_array($firstParamType->getName(), ['int', 'integer'], true));

        // Trigger param sits 2nd for user-first, 3rd for id-first. We want
        // triggers ENABLED: call_trigger=1, notrigger=0.
        $triggerParamIndex = $isIdFirst ? 2 : 1;
        $triggerParam = $params[$triggerParamIndex] ?? null;
        $triggerValue = null;
        if ($triggerParam) {
            $triggerParamName = $triggerParam->getName();
            if ($triggerParamName === 'call_trigger') {
                $triggerValue = 1;
            } elseif (in_array($triggerParamName, ['notrigger', 'noTrigger'], true)) {
                $triggerValue = 0;
            }
        }

        if ($isIdFirst) {
            if ($triggerValue !== null) {
                return $object->update($object->id, $user, $triggerValue);
            }
            return $object->update($object->id, $user);
        }
        if ($triggerValue !== null) {
            return $object->update($user, $triggerValue);
        }
        return $object->update($user);
    }

    /**
     * Delete an object, adapting to the three delete() shapes:
     *   - delete($id, $user, ...)   (Societe)      -> id-first
     *   - delete($user, ...)        (Product,      -> user-first
     *                                Categorie)
     *   - delete($notrigger)        (Contact)      -> no user param
     *
     * @param  object $object  Fetched Dolibarr object.
     * @param  \User  $user    Authenticated user.
     * @return int             Dolibarr delete() result (>0 = ok).
     */
    public static function delete($object, $user)
    {
        $reflection = new \ReflectionMethod($object, 'delete');
        $params = $reflection->getParameters();

        if (empty($params)) {
            return $object->delete();
        }

        $firstParam = $params[0];
        $firstParamName = $firstParam->getName();
        $firstParamType = $firstParam->getType();
        $firstTypeName = ($firstParamType instanceof \ReflectionNamedType) ? $firstParamType->getName() : '';

        // id-first: delete($id, $user, ...)
        if ($firstParamName === 'id'
            || in_array($firstTypeName, ['int', 'integer'], true)) {
            return $object->delete($object->id, $user);
        }

        // user-first: delete(User $user, ...) -- detected by name or type.
        if (in_array($firstParamName, ['user', 'fuser'], true)
            || $firstTypeName === 'User') {
            return $object->delete($user);
        }

        // no-user (Contact::delete($notrigger)): call with defaults so triggers
        // fire and the object deletes $this->id.
        return $object->delete();
    }
}
