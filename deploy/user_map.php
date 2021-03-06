<?php

/**
 * Directory Server user mapping.
 *
 * @category   apps
 * @package    openldap-directory
 * @subpackage configuration
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2011 ClearFoundation
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 3 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/openldap_directory/
 */

///////////////////////////////////////////////////////////////////////////////
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Lesser General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
///////////////////////////////////////////////////////////////////////////////

///////////////////////////////////////////////////////////////////////////////
// B O O T S T R A P
///////////////////////////////////////////////////////////////////////////////

$bootstrap = getenv('CLEAROS_BOOTSTRAP') ? getenv('CLEAROS_BOOTSTRAP') : '/usr/clearos/framework/shared';
require_once $bootstrap . '/bootstrap.php';

///////////////////////////////////////////////////////////////////////////////
// T R A N S L A T I O N S
///////////////////////////////////////////////////////////////////////////////

clearos_load_language('base');
clearos_load_language('users');

///////////////////////////////////////////////////////////////////////////////
// C O N F I G
///////////////////////////////////////////////////////////////////////////////

$info_map = array(
    'username' => array(
        'type' => 'string',
        'field_type' => 'text',
        'field_priority' => 'normal',
        'required' => TRUE,
        'validator' => 'validate_username',
        'validator_class' => 'openldap_directory/User_Driver',
        'description' => lang('base_username'),
        'object_class' => 'inetOrPerson',
        'attribute' => 'uid'
    ),

    'first_name' => array(
        'type' => 'string',
        'field_type' => 'text',
        'field_priority' => 'normal',
        'required' => TRUE,
        'validator' => 'validate_first_name',
        'validator_class' => 'openldap_directory/User_Driver',
        'description' => lang('users_first_name'),
        'object_class' => 'clearAccount',
        'attribute' => 'givenName'
    ),

    'last_name' => array(
        'type' => 'string',
        'field_type' => 'text',
        'field_priority' => 'normal',
        'required' => TRUE,
        'validator' => 'validate_last_name',
        'validator_class' => 'openldap_directory/User_Driver',
        'description' => lang('users_last_name'),
        'object_class' => 'clearAccount',
        'attribute' => 'sn',
    ),

    'home_directory' => array(
        'type' => 'string',
        'field_type' => 'text',
        'field_priority' => 'hidden',
        'required' => FALSE,
        'validator' => 'validate_home_directory',
        'validator_class' => 'openldap_directory/User_Driver',
        'description' => lang('users_home_directory'),
        'object_class' => 'clearAccount',
        'attribute' => 'homeDirectory'
    ),

    'uid_number' => array(
        'type' => 'integer',
        'field_type' => 'integer',
        'field_priority' => 'hidden',
        'required' => FALSE,
        'validator' => 'validate_uid_number',
        'validator_class' => 'openldap_directory/User_Driver',
        'description' => lang('users_uid_number'),
        'object_class' => 'clearAccount',
        'attribute' => 'uidNumber'
    ),

    'gid_number' => array(
        'type' => 'integer',
        'field_type' => 'integer',
        'field_priority' => 'hidden',
        'required' => FALSE,
        'validator' => 'validate_gid_number',
        'validator_class' => 'openldap_directory/User_Driver',
        'description' => lang('users_gid_number'),
        'object_class' => 'clearAccount',
        'attribute' => 'gidNumber'
    ),
);
