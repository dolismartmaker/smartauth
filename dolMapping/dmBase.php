<?php

/**
 * Copyright (c) 2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (c) 2025 Paolo Debaisieux <paolo.debaisieux@cap-rel.fr>
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
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\DolibarrMapping;

abstract class dmBase
{
    protected $type;

    /**
     * name of class for parent object, for exemple Fichinter
     *
     * @var string
     */
    protected $parentClassName;

    /**
     * name of class where you can find extrafields for that object for example Fichinter
     *
     * @var string
     */
    protected $parentClassToUseForExtraFields;

    /**
     * parent element for example fichinter
     *
     * @var string
     */
    protected $parentElementToUseForExtraFields;

    /**
     * parent table name for example fichinter
     *
     * @var string
     */
    protected $parentTableElementToUseForExtraFields;

    /**
     * list of extrafields you want to push as read only on front side
     * (that list should be set via module setup if you want to make that list
     * dynamic for end users)
     *
     * @var array
     */
    protected $extrafieldsRO;

    /**
     * same as $extrafieldsRO but in write, then people can set data into that extrafields
     *
     * @var array
     */
    protected $extrafieldsRW;

    /**
     * list of fields you want to publish on front
     * key is dolibarr field name, value is front field name
     *
     * @var array
     */
    protected $listOfPublishedFields;

    /**
     * name of class for lines, for exemple FichinterLigne or InventoryLine
     *
     * @var string
     */
    protected $parentClassNameForLines;

    /**
     * label for "title of lines", for exemple on FichinterLigne lines title could be "History"
     * (note: that label will be translated thanks to internal dolibarr translation system)
     *
     * @var string
     */
    protected $parentLabelForLines;

    /**
     * you can customize / overcharge fields for for parent object like dolibarr publish for main object
     * if you would like to change some settings, for exemple changing a field of Fichinter main object
     * to make it readonly in your specific use case
     *
     * example: $parentFieldsOverride['duree']['type'] = "duration";
     *          $parentFieldsOverride['duree'] = [ 'type' => "duration", 'required' => "required" ];
     *
     * @var array
     */
    protected $parentFieldsOverride;

    /**
     * fields for lines like dolibarr publish for main object, for exemple FichinterLigne
     * FichinterLigne could not have ->fields then we have to do it in our "custom" object
     *
     * @var array
     */
    protected $parentFieldsForLines;


    /**
     * list of fields you want to publish on front for lines
     * key is dolibarr field name, value is front field name
     *
     * @var array
     */
    protected $listOfPublishedFieldsForLines;
}
