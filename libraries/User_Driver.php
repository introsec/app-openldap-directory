<?php

/**
 * OpenLDAP user driver.
 *
 * @category   Apps
 * @package    OpenLDAP_Accounts
 * @subpackage Libraries
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2003-2011 ClearFoundation
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
// N A M E S P A C E
///////////////////////////////////////////////////////////////////////////////

namespace clearos\apps\openldap_directory;

///////////////////////////////////////////////////////////////////////////////
// B O O T S T R A P
///////////////////////////////////////////////////////////////////////////////

$bootstrap = getenv('CLEAROS_BOOTSTRAP') ? getenv('CLEAROS_BOOTSTRAP') : '/usr/clearos/framework/shared';
require_once $bootstrap . '/bootstrap.php';

///////////////////////////////////////////////////////////////////////////////
// T R A N S L A T I O N S
///////////////////////////////////////////////////////////////////////////////

clearos_load_language('users');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

// Classes
//--------

use \clearos\apps\accounts\Nscd as Nscd;
use \clearos\apps\base\Shell as Shell;
use \clearos\apps\ldap\LDAP_Client as LDAP_Client;
use \clearos\apps\ldap\LDAP_Utilities as LDAP_Utilities;
use \clearos\apps\openldap_directory\Accounts_Driver as Accounts_Driver;
use \clearos\apps\openldap_directory\Group_Driver as Group_Driver;
use \clearos\apps\openldap_directory\Group_Manager_Driver as Group_Manager_Driver;
use \clearos\apps\openldap_directory\OpenLDAP as OpenLDAP;
use \clearos\apps\openldap_directory\Plugin_Driver as Plugin_Driver;
use \clearos\apps\openldap_directory\User_Driver as User_Driver;
use \clearos\apps\openldap_directory\Utilities as Utilities;
use \clearos\apps\users\User_Engine as User_Engine;

clearos_load_library('accounts/Nscd');
clearos_load_library('base/Shell');
clearos_load_library('ldap/LDAP_Client');
clearos_load_library('ldap/LDAP_Utilities');
clearos_load_library('openldap_directory/Accounts_Driver');
clearos_load_library('openldap_directory/Group_Driver');
clearos_load_library('openldap_directory/Group_Manager_Driver');
clearos_load_library('openldap_directory/OpenLDAP');
clearos_load_library('openldap_directory/Plugin_Driver');
clearos_load_library('openldap_directory/User_Driver');
clearos_load_library('openldap_directory/Utilities');
clearos_load_library('users/User_Engine');

// Exceptions
//-----------

use \Exception as Exception;
use \clearos\apps\base\Validation_Exception as Validation_Exception;
use \clearos\apps\users\User_Not_Found_Exception as User_Not_Found_Exception;

clearos_load_library('base/Validation_Exception');
clearos_load_library('users/User_Not_Found_Exception');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * User class.
 *
 * @category   Apps
 * @package    OpenLDAP_Accounts
 * @subpackage Libraries
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2003-2011 ClearFoundation
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 3 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/openldap_directory/
 */

class User_Driver extends User_Engine
{
    ///////////////////////////////////////////////////////////////////////////////
    // C O N S T A N T S
    ///////////////////////////////////////////////////////////////////////////////

    const COMMAND_LDAPPASSWD = '/usr/bin/ldappasswd';
    const PATH_EXTENSIONS = '/var/clearos/openldap_directory/extensions';

    // User policy
    //------------

    const DEFAULT_HOMEDIR_PATH = '/home';
    const DEFAULT_LOGIN = '/sbin/nologin';
    const DEFAULT_USER_GROUP = 'allusers';
    const DEFAULT_USER_GROUP_ID = '63000';

    // User ID ranges
    //---------------

    const UID_RANGE_SYSTEM_MIN = '0';
    const UID_RANGE_SYSTEM_MAX = '499';
    const UID_RANGE_BUILTIN_MIN = '300';
    const UID_RANGE_BUILTIN_MAX = '399';
    const UID_RANGE_NORMAL_MIN = '1000';
    const UID_RANGE_NORMAL_MAX = '59999';

    ///////////////////////////////////////////////////////////////////////////////
    // V A R I A B L E S
    ///////////////////////////////////////////////////////////////////////////////

    protected $ldaph = NULL;
    protected $username = NULL;
    protected $core_classes = array();
    protected $attribute_map = array();
    protected $info_map = array();
    protected $reserved_usernames = array('root', 'manager');
    protected $plugins = array();
    protected $extensions = array();

    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * User constructor.
     */

    public function __construct($username)
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->username = $username;

        // Core LDAP classes
        $this->core_classes = array(
            'top',
            'posixAccount',
            'shadowAccount',
            'inetOrgPerson',
            'clearAccount'
        );

        // Attribute/Info mapping.  The attribute_map contains the reverse mapping.
        include clearos_app_base('openldap_directory') . '/deploy/user_map.php';
        $this->info_map = $info_map;
        $this->attribute_map = array();
    
        foreach ($this->info_map as $info => $details)
            $this->attribute_map[$details['attribute']] = array( 'object_class' => $details['object_class'], 'info' => $info );
    }

    /**
     * Adds a user to the system.
     *
     * @param array $user_info user information
     * @param array $password  password
     *
     * @return void
     * @throws Validation_Exception, Engine_Exception
     */

    public function add($user_info, $password)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph === NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username));
        Validation_Exception::is_valid($this->validate_password($password));
        Validation_Exception::is_valid($this->validate_user_info($user_info));

        // Convert user_info and password into LDAP attributes
        //----------------------------------------------------

        $user_object = $this->_convert_user_array_to_attributes($user_info, FALSE);
        $password_object = $this->_convert_password_to_attributes($password);

        $ldap_object = array_merge($user_object, $password_object);

        // Add LDAP attributes from extensions
        //------------------------------------

        $ldap_object = $this->_add_attributes_hook($user_info, $ldap_object);

        // Validation revisited - check for DN uniqueness
        //-----------------------------------------------
        // 
        // The "common name" is usually a derived field (first name + last name)
        // and it is used for the DN (distinguished name) as a unique identifier.
        // That means two people with the same name cannot exist in the directory.

        $dn = 'cn=' . $this->ldaph->dn_escape($ldap_object['cn']) . ',' . OpenLDAP::get_users_container();

        if ($this->_dn_exists($dn))
            throw new Validation_Exception(lang('users_full_name_already_exists')); 

        // Add the LDAP user object
        //-------------------------

        $this->ldaph->add($dn, $ldap_object);

        // Initialize default group membership
        //------------------------------------

        $this->_initalize_group_memberships();

        // Handle plugins
        //---------------

        $this->_handle_plugins($user_info);

        // Run post-add processing hook
        //-----------------------------

        $this->_add_post_processing_hook($user_info);

        // Ping the synchronizer
        //----------------------

        $this->_synchronize();
    }

    /**
     * Checks the password for the user.
     *
     * @param string $password password for the user
     *
     * @return boolean TRUE if password is correct
     * @throws Engine_Exception, User_Not_Found_Exception
     */

    public function check_password($password)
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));
        Validation_Exception::is_valid($this->validate_password($password));

        // Compare passwords
        //------------------

        if ($this->ldaph === NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        try {
            $attributes = $this->_get_user_attributes();
        } catch (User_Not_Found_Exception $e) {
            return FALSE;
        }

        $sha_password = '{sha}' . LDAP_Utilities::calculate_sha_password($password);

        if (isset($attributes['userPassword'][0]) && ($sha_password === $attributes['userPassword'][0]))
            return TRUE;
        else
            return FALSE;
    }

    /**
     * Deletes a user from the system.
     *
     * The actual delete from LDAP is done asynchronously.  This gives all
     * slave systems a chance to clean up before the object is completely 
     * deleted from LDAP.
     *
     * @return void
     */

    public function delete()
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));

        // Disable the user and set random password for apps without disable
        //------------------------------------------------------------------

        $ldap_object = array();

        $ldap_object['clearAccountStatus'] = User_Engine::STATUS_DISABLED;
        $ldap_object['userPassword'] = '{sha}' . base64_encode(pack('H*', sha1(mt_rand())));

        // Update LDAP attributes from extensions
        //---------------------------------------

        foreach ($this->_get_extensions() as $extension_name => $details) {
            $extension = $this->_load_extension($details);

            if (method_exists($extension, 'delete_attributes_hook')) {
                $hook_object = $extension->delete_attributes_hook();
                $ldap_object = $this->_merge_ldap_objects($ldap_object, $hook_object);
            }
        }

        // Run delete hook
        //----------------

        foreach ($this->_get_extensions() as $extension_name => $details) {
            $extension = $this->_load_extension($details);

            if (method_exists($extension, 'delete_hook'))
                $extension->delete_hook();
        }

        // Modify LDAP object
        //-------------------

        if ($this->ldaph === NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        $dn = $this->_get_dn_for_uid($this->username);

        $this->ldaph->modify($dn, $ldap_object);

        // Ping the synchronizer
        //----------------------

        $this->_synchronize();
    }

    /**
     * Checks if given user exists.
     *
     * @return boolean TRUE if user exists
     * @throws Engine_Exception
     */

    public function exists()
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $attrs = $this->_get_user_attributes();
        } catch (User_Not_Found_Exception $e) {
            // Expected
        }

        if (isset($attrs['uid'][0]))
            return TRUE;
        else
            return FALSE;
    }

    /**
     * Returns the list of groups for given user.
     *
     * @return array a list of groups
     * @throws Engine_Exception
     */

    public function get_group_memberships()
    {
        clearos_profile(__METHOD__, __LINE__);

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));

        $groups = new Group_Manager_Driver();

        $groups_info = $groups->get_details(Group_Driver::TYPE_ALL);

        $group_list = array();

        foreach ($groups_info as $group_name => $group_details) {
            if (in_array($this->username, $group_details['members']))
                $group_list[] = $group_name;
        }

        return $group_list;
    }

    /**
     * Retrieves information for user from LDAP.
     *
     * @return array user details
     * @throws Engine_Exception
     */

    public function get_info()
    {
        clearos_profile(__METHOD__, __LINE__);

        // Get user info
        //--------------

        $attributes = $this->_get_user_attributes();

        $info['core'] = Utilities::convert_attributes_to_array($attributes, $this->info_map);

        // Add group memberships
        //----------------------

        $info['groups'] = $this->get_group_memberships();

        // Add user info from extensions
        //------------------------------

        foreach ($this->_get_extensions() as $extension_name => $details) {
            $extension = $this->_load_extension($details);

            if (method_exists($extension, 'get_info_hook'))
                $info['extensions'][$extension_name] = $extension->get_info_hook($attributes);
        }

        // Add user info map from plugins
        //-------------------------------

        $groups = $this->get_group_memberships();

        foreach ($this->_get_plugins() as $plugin => $details) {
            $plugin_name = $plugin . '_plugin';
            $state = (in_array($plugin_name, $groups)) ? TRUE : FALSE;
            $info['plugins'][$plugin] = $state;
        }

        return $info;
    }

    /**
     * Retrieves default information for a new user.
     *
     * @return array user details
     * @throws Engine_Exception
     */

    public function get_info_defaults()
    {
        clearos_profile(__METHOD__, __LINE__);

        foreach ($this->_get_extensions() as $extension_name => $details) {
            $extension = $this->_load_extension($details);

            if (method_exists($extension, 'get_info_defaults_hook'))
                $info['extensions'][$extension_name] = $extension->get_info_defaults_hook($attributes);
        }

        return $info;
    }

    /**
     * Retrieves full information map for user.
     *
     * @throws Engine_Exception
     *
     * @return array user details
     */

    public function get_info_map()
    {
        clearos_profile(__METHOD__, __LINE__);

        $info_map = array();

        $info_map['core'] = $this->info_map;

        // Add user info map from extensions
        //----------------------------------

        foreach ($this->_get_extensions() as $extension_name => $details) {
            $extension = $this->_load_extension($details);

            if (method_exists($extension, 'get_info_map_hook'))
                $info_map['extensions'][$extension_name] = $extension->get_info_map_hook();
        }

        // Add user info map from plugins
        //-------------------------------

        foreach ($this->_get_plugins() as $plugin => $details) {
            $plugin_name = $plugin . '_plugin';
            $info_map['plugins'][] = $plugin;
        }

        return $info_map;
    }

    /**
     * Reset the passwords for the user.
     *
     * Similar to set_password, but it uses administrative privileges.  This is
     * typically used for resetting a password while bypassing password
     * policies.  For example, an administrator may need to set a password
     * even when the password policy dictates that the password is not allowed
     * to change (minimum password age).
     *
     * @param string  $password       password
     * @param string  $verify         password verify
     * @param string  $requested_by   username requesting the password change
     * @param boolean $include_samba  workaround for Samba password changes
     *
     * @return void
     * @throws Engine_Exception, Validation_Exception
     */

    public function reset_password($password, $verify, $requested_by, $include_samba = TRUE)
    {
        clearos_profile(__METHOD__, __LINE__);

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));
        Validation_Exception::is_valid($this->validate_username($requested_by, FALSE, FALSE));
        // FIXME: Validate password/verify

        // Set passwords in LDAP
        //----------------------

        if ($this->ldaph === NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        $dn = $this->_get_dn_for_uid($this->username);

        $ldap_object = $this->_convert_password_to_attributes($password);

        $this->ldaph->modify($dn, $ldap_object);

        $this->_synchronize();
    }

    /**
     * Sets the password for the user.
     *
     * Ignore the include_samba flag,  It is a workaround required for password
     * changes using the change password tool from Windows desktops.
     *
     * @param string  $oldpassword   old password
     * @param string  $password      password
     * @param string  $verify        password verify
     * @param string  $requested_by  username requesting the password change
     * @param boolean $include_samba workaround for Samba password changes
     *
     * @return void
     * @throws Engine_Exception, Validation_Exception
     */

    public function set_password($oldpassword, $password, $verify, $requested_by, $include_samba = TRUE)
    {
        clearos_profile(__METHOD__, __LINE__);

        // TODO: something odd is going on when password histories are enabled.
        // The following block of code will fail if the sleep(1) is omitted.
        //
        //    $password = "password';
        //    $user = new User("test1");
        //    $user_info['telephone'] = '867-5309';
        //    $user->Update($user_info);
        //    $user->SetPassword($password, $password, "testscript");

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));
        Validation_Exception::is_valid($this->validate_username($requested_by, FALSE, FALSE));
        // FIXME: Validate password/verify

        // Sanity check the password using the ldappasswd command
        //-------------------------------------------------------

        if ($this->ldaph === NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        $dn = $this->_get_dn_for_uid($this->username);

        $options['validate_exit_code'] = FALSE;

        $shell = new Shell();
        $intval = $shell->Execute(self::COMMAND_LDAPPASSWD, 
            '-x ' .
            '-D "' . $dn . '" ' .
            '-w "' . $oldpassword . '" ' .
            '-s "' . $password . '" ' .
            '"' . $dn . '"', 
            FALSE, $options);
    
        if ($intval != 0)
            $output = $shell->get_output();

        if (! empty($output)) {
            // Dirty.  Try to catch common error strings so that we can translate.
            $error_message = isset($output[1]) ? $output[1] : $output[0]; // Default if our matching fails

            foreach ($output as $line) {
                if (preg_match("/Invalid credentials/", $line))
                    $error_message = lang('users_old_password_invalid');
                else if (preg_match("/Password is in history of old passwords/", $line))
                    $error_message = lang('users_password_in_history');
                else if (preg_match("/Password is not being changed from existing value/", $line))
                    $error_message = lang('users_password_not_changed');
                else if (preg_match("/Password fails quality checking policy/", $line))
                    $error_message = lang('users_password_violates_quality_check');
                else if (preg_match("/Password is too young to change/", $line))
                    $error_message = lang('users_password_is_too_young');
            }

            throw new Validation_Exception($error_message);
        }

        // Convert password into LDAP attributes
        //--------------------------------------

        $ldap_object = $this->_convert_password_to_attributes($password);

        // Add LDAP attributes from extensions
        //------------------------------------

        foreach ($this->_get_extensions() as $extension_name => $details) {
            $extension = $this->_load_extension($details);

            if (method_exists($extension, 'set_password_attributes_hook')) {
                $hook_object = $extension->set_password_attributes_hook($password, $ldap_object);
                $ldap_object = $this->_merge_ldap_objects($ldap_object, $hook_object);
            }
        }

        // Set passwords in LDAP
        //----------------------

        sleep(2); // see comment

        $this->ldaph->modify($dn, $ldap_object);

        $this->_synchronize();
    }

    /**
     * Unlocks a user account.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function unlock()
    {
        clearos_profile(__METHOD__, __LINE__);

        // Run unlock hook
        //----------------

        foreach ($this->_get_extensions() as $extension_name => $details) {
            $extension = $this->_load_extension($details);

            if (method_exists($extension, 'unlock_hook'))
                $extension->unlock_hook($this->username);
        }

        $this->_synchronize();
    }

    /**
     * Updates a user on the system.
     *
     * @param array $user_info user information
     * @param array $acl access control list
     *
     * @return void
     * @throws Validation_Exception, Engine_Exception, User_Not_Found_Exception
     */

    public function update($user_info, $acl = NULL)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph == NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        // Validate
        //---------

        Validation_Exception::is_valid($this->validate_username($this->username, FALSE, FALSE));
        Validation_Exception::is_valid($this->validate_user_info($user_info));
        // FIXME: acl

        // User does not exist error
        //--------------------------

        if (! $this->exists()) 
            throw new User_Not_Found_Exception();

        // Convert user info to LDAP object
        //---------------------------------

        $ldap_object = $this->_convert_user_array_to_attributes($user_info, TRUE);

        // Update LDAP attributes from extensions
        //---------------------------------------

        foreach ($this->_get_extensions() as $extension_name => $details) {
            $extension = $this->_load_extension($details);

            if (method_exists($extension, 'update_attributes_hook')) {
                $hook_object = $extension->update_attributes_hook($user_info, $ldap_object);
                $ldap_object = $this->_merge_ldap_objects($ldap_object, $hook_object);
            }
        }

        // Handle name change (which changes DN)
        //--------------------------------------

        $old_attributes = $this->_get_user_attributes();

        $rdn = 'cn=' . LDAP_Client::dn_escape($ldap_object['cn']);
        $new_dn = $rdn . ',' . OpenLDAP::get_users_container();

        if ($new_dn !== $old_attributes['dn'])
            $this->ldaph->rename($old_attributes['dn'], $rdn, OpenLDAP::get_users_container());

        // Modify LDAP object
        //-------------------

        $this->ldaph->modify($new_dn, $ldap_object);

        // Handle plugins
        //---------------

        $this->_handle_plugins($user_info);

        // Ping the synchronizer
        //----------------------

        $this->_synchronize();
    }

    ///////////////////////////////////////////////////////////////////////////////
    // V A L I D A T I O N   M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Validation routine for first name.
     *
     * @param string $name first name
     *
     * @return string error message if first name is invalid
     */

    public function validate_first_name($name)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (preg_match("/([:;\/#!@])/", $name))
            return lang('users_first_name_invalid');
    }

    /**
     * Validation routine for GID number.
     *
     * @param integer $gid_number GID number
     *
     * @return string error message if GID number is invalid
     */

    public function validate_gid_number($gid_number)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! preg_match('/^[0-9]+$/', $gid_number))
            return lang('users_group_id_invalid');
    }

    /**
     * Validation routine for home directory
     *
     * @param string $homedir home directory
     *
     * @return string error message if home directory is invalid
     */

    public function validate_home_directory($homedir)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (preg_match("/([:;#!@])/", $homedir))
            return lang('users_home_directory_invalid');
    }

    /**
     * Validation routine for last name.
     *
     * @param string $name last name
     *
     * @return string error message if last name is invalid
     */

    public function validate_last_name($name)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (preg_match("/([:;\/#!@])/", $name))
            return lang('users_last_name_invalid');
    }

    /**
     * Password validation routine.
     *
     * @param string $password password
     *
     * @return boolean TRUE if password is valid
     */

    public function validate_password($password)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (preg_match("/[\|;\*]/", $password) || !preg_match("/^[a-zA-Z0-9]/", $password))
            return lang('users_password_invalid');
    }

    /**
     * Password/verify validation routine.
     *
     * @param string $password password
     * @param string $verify verify
     *
     * @return boolean TRUE if password and verify are valid and equal
     */

    public function validate_password_and_verify($password, $verify)
    {
        clearos_profile(__METHOD__, __LINE__);

        $is_valid = TRUE;

        if (empty($password)) {
            $this->AddValidationError(LOCALE_LANG_ERRMSG_REQUIRED_PARAMETER_IS_MISSING . " - " . LOCALE_LANG_PASSWORD, __METHOD__, __LINE__);
            $is_valid = FALSE;
        }

        if (empty($verify)) {
            $this->AddValidationError(LOCALE_LANG_ERRMSG_REQUIRED_PARAMETER_IS_MISSING . " - " . LOCALE_LANG_VERIFY, __METHOD__, __LINE__);
            $is_valid = FALSE;
        }

        if ($is_valid) {
            if ($password == $verify) {
                $is_valid = $this->validate_password($password);
            } else {
                $this->AddValidationError(LOCALE_LANG_ERRMSG_PASSWORD_MISMATCH, __METHOD__, __LINE__);
                $is_valid = FALSE;
            }
        }

        return $is_valid;
    }

    /**
     * Validation routine for UID number.
     *
     * @param integer $uid_number UID number
     *
     * @return boolean TRUE if UID number is valid
     */

    public function validate_uid_number($uid_number)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! preg_match('/^[0-9]+$/', $uid_number))
            return lang('users_user_id_invalid');
        else if ($uid_number > self::UID_RANGE_NORMAL_MAX)
            return lang('users_user_id_invalid');
    }

    /**
     * Validation routine for username.
     *
     * @param string  $username         username
     * @param boolean $check_uniqueness check for uniqueness
     * @param boolean $allow_reserved   check for reserved usernames
     *
     * @return string error message if username is invalid
     */

    public function validate_username($username, $check_uniqueness = TRUE, $check_reserved = TRUE)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (!preg_match("/^([a-z0-9_\-\.\$]+)$/", $username))
            return lang('users_username_invalid');

        if ($check_reserved && in_array($username, $this->reserved_usernames))
            return lang('users_username_is_reserved');

        if ($check_uniqueness) {
            $openldap = new OpenLDAP();
            $message = $openldap->check_uniqueness($username);

            if ($message)
                return $message;
        }
    }

    /**
     * Validates a user_info array.
     *
     * @param array $user_info user information array
     * @param boolean $is_modify set to TRUE if using results on LDAP modif
     *
     * @return boolean TRUE if user_info is valid
     * @throws Engine_Exception
     */

    public function validate_user_info($user_info, $is_modify = FALSE)
    {
        clearos_profile(__METHOD__, __LINE__);

        $is_valid = TRUE;
        $invalid_attrs = array();

        // Check user_info type
        //---------------------

        if (!is_array($user_info))
            throw new Validation_Exception(lang('users_user_information_invalid'));

        // Validate user information using validator defined in $this->info_map
        //--------------------------------------------------------------------

        foreach ($user_info as $attribute => $detail) {
            if (isset($this->info_map[$attribute]) && isset($this->info_map[$attribute]['validator'])) {
                // TODO: afterthought -- password/verify check is done below
                if ($attribute == 'password')
                    continue;

                $validator = $this->info_map[$attribute]['validator'];

                Validation_Exception::is_valid($this->$validator($detail));
            }
        }
//pete FIXME
return;

        // Validate passwords
        //-------------------

        if (!empty($user_info['password']) || !empty($user_info['verify'])) {
            if (!($this->validate_password_and_verify($user_info['password'], $user_info['verify']))) {
                $is_valid = FALSE;
                $invalid_attrs[] = 'password';
            }
        }

        // When adding a new user, check for missing attributes
        //-----------------------------------------------------

        if (! $is_modify) {
            foreach ($this->info_map as $attribute => $details) {
                if (empty($user_info[$attribute]) && 
                    ($details['required'] == TRUE) &&
                    (!in_array($attribute, $invalid_attrs))
                    ) {
                        $is_valid = FALSE;
                        $this->AddValidationError(
                            LOCALE_LANG_ERRMSG_REQUIRED_PARAMETER_IS_MISSING . " - " . $details['locale'], __METHOD__, __LINE__
                        );
                } 
            }
        }

        if ($is_valid)
            return TRUE;
        else
            return FALSE;
    }

    ///////////////////////////////////////////////////////////////////////////////
    // P R I V A T E   M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Runs add_attributes hook in extensions.
     */

    protected function _add_attributes_hook($user_info, $ldap_object)
    {
        clearos_profile(__METHOD__, __LINE__);

        foreach ($this->_get_extensions() as $extension_name => $details) {
            $extension = $this->_load_extension($details);

            if (method_exists($extension, 'add_attributes_hook')) {
                $hook_object = $extension->add_attributes_hook($user_info, $ldap_object);
                $ldap_object = $this->_merge_ldap_objects($ldap_object, $hook_object);
            }
        }

        return $ldap_object;
    }

    /**
     * Runs post-processing hook.
     *
     * @param array $user_info user info
     */
    protected function _add_post_processing_hook($user_info)
    {
        clearos_profile(__METHOD__, __LINE__);

        foreach ($this->_get_extensions() as $extension_name => $details) {
            $extension = $this->_load_extension($details);

            if (method_exists($extension, 'add_post_processing_hook'))
                $extension->add_post_processing_hook($this->username, $user_info);
        }
    }

    /**
     * Adds the parity bit to the given DES key.
     *
     * @access private
     * @param  string  $key 7-Bytes Key without parity
     *
     * @return string
     */

    protected function _add_parity_to_des($key)
    {
        clearos_profile(__METHOD__, __LINE__);

        static $odd_parity = array(
                1,  1,  2,  2,  4,  4,  7,  7,  8,  8, 11, 11, 13, 13, 14, 14,
                16, 16, 19, 19, 21, 21, 22, 22, 25, 25, 26, 26, 28, 28, 31, 31,
                32, 32, 35, 35, 37, 37, 38, 38, 41, 41, 42, 42, 44, 44, 47, 47,
                49, 49, 50, 50, 52, 52, 55, 55, 56, 56, 59, 59, 61, 61, 62, 62,
                64, 64, 67, 67, 69, 69, 70, 70, 73, 73, 74, 74, 76, 76, 79, 79,
                81, 81, 82, 82, 84, 84, 87, 87, 88, 88, 91, 91, 93, 93, 94, 94,
                97, 97, 98, 98,100,100,103,103,104,104,107,107,109,109,110,110,
                112,112,115,115,117,117,118,118,121,121,122,122,124,124,127,127,
                128,128,131,131,133,133,134,134,137,137,138,138,140,140,143,143,
                145,145,146,146,148,148,151,151,152,152,155,155,157,157,158,158,
                161,161,162,162,164,164,167,167,168,168,171,171,173,173,174,174,
                176,176,179,179,181,181,182,182,185,185,186,186,188,188,191,191,
                193,193,194,194,196,196,199,199,200,200,203,203,205,205,206,206,
                208,208,211,211,213,213,214,214,217,217,218,218,220,220,223,223,
                224,224,227,227,229,229,230,230,233,233,234,234,236,236,239,239,
                241,241,242,242,244,244,247,247,248,248,251,251,253,253,254,254);

        $bin = '';
        for ($i = 0; $i < strlen($key); $i++)
            $bin .= sprintf('%08s', decbin(ord($key{$i})));

        $str1 = explode('-', substr(chunk_split($bin, 7, '-'), 0, -1));
        $x = '';

        foreach($str1 as $s)
            $x .= sprintf('%02s', dechex($odd_parity[bindec($s . '0')]));

        return pack('H*', $x);
    }

    /**
     * Converts a password into LDAP attributes.
     *
     * @param string $password password
     *
     * @access private
     * @return array LDAP attribute array
     * @throws Engine_Exception, Validation_Exception
     */

    protected function _convert_password_to_attributes($password)
    {
        clearos_profile(__METHOD__, __LINE__);

        $ldap_object = array();

        $ldap_object['userPassword'] = '{sha}' . LDAP_Utilities::calculate_sha_password($password);
        $ldap_object['clearSHAPassword'] = $ldap_object['userPassword'];
        $ldap_object['clearSHA1Password'] = LDAP_Utilities::convert_sha_to_sha1($ldap_object['clearSHAPassword']);
        $ldap_object['clearMicrosoftNTPassword'] = LDAP_Utilities::calculate_nt_password($password);

        return $ldap_object;
    }

    /**
     * Converts a user_info array into LDAP attributes.
     *
     * @access private
     * @param array   $user_info user information array
     * @param boolean $is_modify set to TRUE if using results on LDAP modify
     *
     * @return array LDAP attribute array
     * @throws Engine_Exception, Validation_Exception
     */

    protected function _convert_user_array_to_attributes($user_info, $is_modify)
    {
        clearos_profile(__METHOD__, __LINE__);

        /**
         * This method is the meat and potatoes of the User class.  There
         * are quite a few non-intuitive steps in here, but hopefully the 
         * documentation will guide the way.
         */

        $ldap_object = array();
        $old_attributes = array();
        $openldap = new OpenLDAP();

        try {
            $old_attributes = $this->_get_user_attributes();
        } catch (User_Not_Found_Exception $e) {
            // Not fatal
        }

        /**
         * Step 1 - convert user_info fields to LDAP fields
         *
         * Use the utility class for this job.
         */

        if (isset($user_info['core']))
            $ldap_object = Utilities::convert_array_to_attributes($user_info['core'], $this->info_map);

        /**
         * Step 2 - handle derived fields
         *
         * Some LDAP attributes are derived from other variables, notably:
         * - uid: this is the username given in the constructor
         * - cn: this is the "first name + last name"
         *
         * For some built-in accounts (e.g. Flexshare) it is more desirable
         * to explicitly set the 'cn' to something other than 
         * "first name + last name" , so we (quietly) allow it.
         */

        $ldap_object['uid'] = $this->username;

        if (isset($user_info['core']['cn'])) {
            $ldap_object['cn'] = $user_info['core']['cn'];
        } else {
            if (isset($user_info['core']['first_name']) || isset($user_info['core']['last_name']))
                $ldap_object['cn'] = $user_info['core']['first_name'] . ' ' . $user_info['core']['last_name'];
            else
                $ldap_object['cn'] = $old_attributes['cn'][0];
        }

        /**
         * Step 3 - handle defaults
         *
         * On a new user record, some attributes can be set to defaults.  For
         * the 'uid_number' and 'gid_number', we allow the developer to specify
         * the values.  For all other cases, defaults are forced to specific
         * values.
         */

        if (! $is_modify) {
            if (isset($user_info['core']['uid_number']))
                $ldap_object['nidNumber'] = $user_info['core']['uid_number'];
            else {
                $accounts = new Accounts_Driver();
                $ldap_object['uidNumber'] = $accounts->get_next_uid_number();
            }

            $ldap_object['loginShell'] = User_Driver::DEFAULT_LOGIN;

            if (isset($user_info['core']['gid_number']))
                $ldap_object['gidNumber'] = $user_info['core']['gid_number'];
            else
                $ldap_object['gidNumber'] = User_Driver::DEFAULT_USER_GROUP_ID;

            if (isset($user_info['core']['home_directory'])) 
                $ldap_object['homeDirectory'] = $user_info['core']['home_directory'];
            else
                $ldap_object['homeDirectory'] = User_Driver::DEFAULT_HOMEDIR_PATH . '/' . $this->username;

            if (isset($user_info['core']['status'])) 
                $ldap_object['clearAccountStatus'] = $user_info['core']['status'];
            else
                $ldap_object['clearAccountStatus'] = User_Engine::STATUS_ENABLED;
        }

        /**
         * Step 4 - set core object classes.
         *
         * If this is an update, we need to make sure the objectclass list
         * includes pre-existing classes.
         */

        if (isset($old_attributes['objectClass'])) {
            $old_classes = $old_attributes['objectClass'];
            array_shift($old_classes);
            $ldap_object['objectClass'] = $this->_merge_ldap_object_classes($this->core_classes, $old_classes);
        } else {
            $ldap_object['objectClass'] = $this->core_classes;
        }

        return $ldap_object;
    }

    /**
     * Validation routine for DN (distinguised name).
     *
     * @param string $dn distinguised name
     *
     * @return string error message if DN is invalid
     * @throws Engine_Exception
     */

    protected function _dn_exists($dn)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph == NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        if ($this->ldaph->exists($dn))
            return TRUE;
        else
            return FALSE;
    }

    /**
     * Returns DN for given user ID (username).
     *
     * @param string $uid user ID
     *
     * @return string DN
     * @throws Engine_Exception
     */

    protected function _get_dn_for_uid($uid)
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph === NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        $this->ldaph->search('(&(objectclass=posixAccount)(uid=' . $this->ldaph->escape($uid) . '))');
        $entry = $this->ldaph->get_first_entry();

        $dn = '';

        if ($entry)
            $dn = $this->ldaph->get_dn($entry);

        return $dn;
    }

    /**
     * Returns extension list.
     *
     * @access private
     * @return array extension list
     */

    protected function _get_extensions()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! empty($this->extensions))
            return $this->extensions;

        $accounts = new Accounts_Driver();

        $this->extensions = $accounts->get_extensions();

        return $this->extensions;
    }

    /**
     * Returns plugins list.
     *
     * @access private
     * @return array extension list
     */

    protected function _get_plugins()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! empty($this->plugins))
            return $this->plugins;

        $accounts = new Accounts_Driver();

        $this->plugins = $accounts->get_plugins();

        return $this->plugins;
    }

    /**
     * Returns LDAP user information in hash array.
     *
     * @access private
     *
     * @return array hash array of user information
     * @throws Engine_Exception, User_Not_Found_Exception
     */

    protected function _get_user_attributes()
    {
        clearos_profile(__METHOD__, __LINE__);

        if ($this->ldaph == NULL)
            $this->ldaph = Utilities::get_ldap_handle();

        $dn = $this->_get_dn_for_uid($this->username);

        $attributes = $this->ldaph->read($dn);
        $attributes['dn'] = $dn;

        if (!isset($attributes['uid'][0]))
            throw new User_Not_Found_Exception();

        return $attributes;
    }

    /**
     * Handles plugin attributes.
     *
     * @param array $user_info user info array
     *
     * @return void
     * @throws Engine_Exception
     */

    protected function _handle_plugins($user_info)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (empty($user_info['plugins']))
            return;

        foreach ($user_info['plugins'] as $plugin_name => $info) {
            $plugin = new Plugin_Driver($plugin_name);

            if ($info['state'])
                $plugin->add_member($this->username);
            else
                $plugin->delete_member($this->username);
        }
    }

    /**
     * Initializes default group memberships.
     *
     * Both Linux and Windows (and perhaps other operating systems) require
     * a default group to be assigned.  
     *
     * @return void
     */

    public function _initalize_group_memberships()
    {
        clearos_profile(__METHOD__, __LINE__);

        $group = new Group_Driver(User_Driver::DEFAULT_USER_GROUP);
        $group->add_member($this->username);
    }

    /**
     * Loads an extension.
     *
     * @param array $details extension details
     * @return object extension object
     */

    protected function _load_extension($details)
    {
        clearos_profile(__METHOD__, __LINE__);

        clearos_load_library($details['app'] . '/OpenLDAP_User_Extension');

        $class = '\clearos\apps\\' . $details['app'] . '\OpenLDAP_User_Extension';
        $extension = new $class();

        return $extension;
    }

    /**
     * Merges two LDAP object class lists.
     *
     * @param array $array1 LDAP object class list
     * @param array $array2 LDAP object class list
     *
     * @return array object class list
     */

    protected function _merge_ldap_object_classes($array1, $array2)
    {
        clearos_profile(__METHOD__, __LINE__);

        $raw_merged = array_merge($array1, $array2);
        $raw_merged = array_unique($raw_merged);

        // PHPism.  Merged arrays have gaps in the keys of the array.
        // The LDAP object barfs on this, so we need to re-key.

        $merged = array();

        foreach ($raw_merged as $class)
            $merged[] = $class;

        return $merged;
    }

    /**
     * Merges two LDAP object arrays.
     *
     * @param array $array1 LDAP object array
     * @param array $array2 LDAP object array
     *
     * @return array LDAP object array
     */

    protected function _merge_ldap_objects($array1, $array2)
    {
        clearos_profile(__METHOD__, __LINE__);

        // Handle object class array

        if (isset($array1['objectClass']) && isset($array2['objectClass']))
            $object_classes = $this->_merge_ldap_object_classes($array1['objectClass'], $array2['objectClass']);
        else if (isset($array1['objectClass']))
            $object_classes = $array1['objectClass'];
        else if (isset($array2['objectClass']))
            $object_classes = $array2['objectClass'];

        $ldap_object = array_merge($array1, $array2);

        if (isset($object_classes))
            $ldap_object['objectClass'] = $object_classes;

        return $ldap_object;
    }

    /**
     * Signals LDAP synchronize daemon.
     *
     * @access private
     * @return void
     */

    protected function _synchronize()
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $nscd = new Nscd();
            $nscd->clear_cache();
        } catch (Exception $e) {
            // Not fatal
        }
    }
}
