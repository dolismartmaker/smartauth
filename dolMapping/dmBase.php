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
    protected $_type;

    /**
     * name of class where you can find extrafields for that object for example Fichinter
     *
     * @var string
     */
    public $parentClassToUseForExtraFields;

    /**
     * parent element for example fichinter
     *
     * @var string
     */
    public $parentElementToUseForExtraFields;

    /**
     * parent table name for example fichinter
     *
     * @var string
     */
    public $parentTableElementToUseForExtraFields;

    /**
     * list of extrafields you want to push as read only on front side
     * (that list should be set via module setup if you want to make that list
     * dynamic for end users)
     *
     * @var array
     */
    public $extrafieldsRO;

    /**
     * same as $extrafieldsRO but in write, then people can set data into that extrafields
     *
     * @var array
     */
    public $extrafieldsRW;

    /**
     * list of fields you want to publish on front
     * key is dolibarr field name, value is front field name
     *
     * @var array
     */
    protected $_listOfPublishedFields;

    /**
     * name of class for lines, for exemple FichinterLigne or InventoryLine
     *
     * @var string
     */
    public $parentClassNameForLines;

    /**
     * label for "title of lines", for exemple on FichinterLigne lines title could be "History"
     * (note: that label will be translated thanks to internal dolibarr translation system)
     *
     * @var string
     */
    public $parentLabelForLines;

    /**
     * fields for lines like dolibarr publish for main object, for exemple FichinterLigne
     * FichinterLigne could not have ->fields then we have to do it in our "custom" object
     *
     * @var array
     */
    public $parentFieldsForLines;


    /**
     * list of fields you want to publish on front for lines
     * key is dolibarr field name, value is front field name
     *
     * @var array
     */
    protected $_listOfPublishedFieldsForLines;
}
