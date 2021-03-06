<?php

namespace AirNZ\SimpleSubsites\Model;

use AirNZ\SimpleSubsites\Extensions\CMSMainExtension;
use AirNZ\SimpleSubsites\Model\Subsite;
use SilverStripe\Admin\CMSMenu;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\Session;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Resettable;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\i18n\i18n;

/**
 * A dynamically created subsite. SiteTree objects can now belong to a subsite.
 * You can simulate subsite access without setting up virtual hosts by appending ?SubsiteID=<ID> to the request.
 */
class Subsite extends DataObject implements Resettable
{
    private static $table_name = 'Subsite';

    /**
     * @var $use_session_subsiteid Boolean Set to TRUE when using the CMS and false
     * when browsing the frontend of a website.
     *
     * @todo Remove flag once the Subsite CMS works without session state,
     * similarly to the Translatable module.
     */
    public static $use_session_subsiteid = false;

    /**
     * @var boolean $disable_subsite_filter If enabled, bypasses the query decoration
     * to limit DataObject::get*() calls to a specific subsite. Useful for debugging.
     */
    public static $disable_subsite_filter = false;

    /**
     * Allows you to force a specific subsite ID, or comma separated list of IDs.
     * Only works for reading. An object cannot be written to more than 1 subsite.
     */
    public static $force_subsite = null;

    /**
     *
     * @var boolean
     */
    public static $write_hostmap = true;

    /**
     * Memory cache of accessible sites
     *
     * @array
     */
    private static $_cache_accessible_sites = [];
    private static $_cache_accessible_sites_ids = [];

    /**
     * Memory cache of subsite id for domains
     *
     * @var array
     */
    private static $_cache_subsite_for_domain = [];

    /**
     * @return array
     */
    private static $summary_fields = [
        'Title',
        'Domain',
    ];

    /**
     * Gets the subsite currently set in the session.
     *
     * @uses ControllerSubsites->controllerAugmentInit()
     * @return Subsite
     */
    public static function currentSubsite()
    {
        // get_by_id handles caching so we don't have to
        return DataObject::get_by_id(Subsite::class, self::currentSubsiteID());
    }

    /**
     * This function gets the current subsite ID from the session. It used in the backend so Ajax requests
     * use the correct subsite. The frontend handles subsites differently. It calls getSubsiteIDForDomain
     * directly from ModelAsController::getNestedController.
     *
     * You can simulate subsite access without creating virtual hosts by appending ?SubsiteID=<ID> to the request.
     *
     * @todo Pass $request object from controller so we don't have to rely on $_GET
     *
     * @param boolean $cache
     * @param boolean $createIfNone Create a default subsite if one doesn't exist
     * @return int ID of the current subsite instance
     */
    public static function currentSubsiteID()
    {
        $id = null;

        if (isset($_GET['SubsiteID'])) {
            $id = (int)$_GET['SubsiteID'];
        } elseif (Subsite::$use_session_subsiteid) {
            $session = Controller::curr()->getRequest()->getSession();
            $id = $session->get('SubsiteID');
        }

        if ($id === null) {
            $id = self::getSubsiteIDForDomain();
        }

        return (int)$id;
    }

    /**
     * Switch to another subsite through storing the subsite identifier in the current PHP session.
     * Only takes effect when {@link Subsite::$use_session_subsiteid} is set to TRUE.
     *
     * @param int|Subsite $subsite Either the ID of the subsite, or the subsite object itself
     */
    public static function changeSubsite($subsite)
    {
        // Session subsite change only meaningful if the session is active.
        // Otherwise we risk setting it to wrong value, e.g. if we rely on currentSubsiteID.
        if (!Subsite::$use_session_subsiteid) {
            return;
        }

        if (is_object($subsite)) {
            $subsiteID = $subsite->ID;
        } else {
            $subsiteID = $subsite;
        }

        $session = Controller::curr()->getRequest()->getSession();
        $session->set('SubsiteID', (int)$subsiteID);

        // Set locale
        if (is_object($subsite) && $subsite->Language != '') {
            $locale = i18n::get_locale_from_lang($subsite->Language);
            if ($locale) {
                i18n::set_locale($locale);
            }
        }

        Permission::reset();
    }

    /**
     * Get a matching subsite for the given host, or for the current HTTP_HOST.
     * Supports "fuzzy" matching of domains by placing an asterisk at the start of end of the string,
     * for example matching all subdomains on *.example.com with one subsite,
     * and all subdomains on *.example.org on another.
     *
     * @param $host The host to find the subsite for.  If not specified, $_SERVER['HTTP_HOST'] is used.
     * @return int Subsite ID or False
     */
    public static function getSubsiteIDForDomain($host = null)
    {
        if ($host == null && isset($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        }
        if (!$host) {
            return false;
        }

        if (!isset(self::$_cache_subsite_for_domain[$host])) {
            $subsite = DataObject::get_one(static::class, ['Domain' => $host]);
            self::$_cache_subsite_for_domain[$host] = $subsite ? $subsite->ID : false;
        }

        return self::$_cache_subsite_for_domain[$host];
    }

    /**
     *
     * @param string $className
     * @param string $filter
     * @param string $sort
     * @param string $join
     * @param string $limit
     * @return DataList
     */
    public static function get_from_all_subsites($className, $filter = "", $sort = "", $join = "", $limit = "")
    {
        $result = DataObject::get($className, $filter, $sort, $join, $limit);
        $result = $result->setDataQueryParam('Subsite.filter', false);
        return $result;
    }

    /**
     * Disable the sub-site filtering; queries will select from all subsites
     */
    public static function disable_subsite_filter($disabled = true)
    {
        self::$disable_subsite_filter = $disabled;
    }

    /**
     * Flush caches on database reset
     */
    public static function reset()
    {
        self::$_cache_accessible_sites = [];
        self::$_cache_subsite_for_domain = [];
        self::$_cache_accessible_sites_ids = [];
    }

    /**
     * Return all subsites, regardless of permissions (augmented with main site).
     *
     * @return SS_List List of {@link Subsite} objects (DataList or ArrayList).
     */
    public static function all_sites()
    {
        return Subsite::get();
    }

    /*
     * Returns an ArrayList of the subsites accessible to the current user.
     * It's enough for any section to be accessible for the site to be included.
     *
     * @return ArrayList of {@link Subsite} instances.
     */
    public static function all_accessible_sites($member = null)
    {
        // Rationalise member arguments
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        if (!$member) {
            return new ArrayList();
        }
        if (!is_object($member)) {
            $member = DataObject::get_by_id(Member::class, $member);
        }

        $subsites = new ArrayList();

        // Collect subsites for all sections.
        $menu = CMSMenu::get_viewable_menu_items($member);
        foreach ($menu as $candidate) {
            if ($candidate->controller && singleton($candidate->controller)->hasExtension(CMSMainExtension::class)) {
                $accessibleSites = singleton($candidate->controller)->sectionSites($member);

                // Replace existing keys so no one site appears twice.
                $subsites->merge($accessibleSites);
            }
        }

        $subsites->removeDuplicates();

        return $subsites;
    }

    /**
     * Return the subsites that the current user can access by given permission.
     * Sites will only be included if they have a Title.
     *
     * @param $permCode array|string Either a single permission code or an array of permission codes.
     * @param $member
     * @return DataList of {@link Subsite} instances
     */
    public static function accessible_sites($permCode, $member = null)
    {
        // Rationalise member arguments
        if (!$member) {
            $member = Security::getCurrentUser();
        }
        if (!$member) {
            return new ArrayList();
        }
        if (!is_object($member)) {
            $member = DataObject::get_by_id(Member::class, $member);
        }

        // Rationalise permCode argument
        if (is_array($permCode)) {
            $SQL_codes = "'" . implode("', '", Convert::raw2sql($permCode)) . "'";
        } else {
            $SQL_codes = "'" . Convert::raw2sql($permCode) . "'";
        }

        // Cache handling
        $cacheKey = $SQL_codes . '-' . $member->ID;
        if (isset(self::$_cache_accessible_sites[$cacheKey])) {
            return self::$_cache_accessible_sites[$cacheKey];
        }

        /** @skipUpgrade */
        $subsites = DataList::create(static::class)
            ->leftJoin('Group_Subsites', "\"Group_Subsites\".\"SubsiteID\" = \"Subsite\".\"ID\"")
            ->innerJoin('Group', "\"Group\".\"ID\" = \"Group_Subsites\".\"GroupID\" OR \"Group\".\"AccessAllSubsites\" = 1")
            ->innerJoin('Group_Members', "\"Group_Members\".\"GroupID\"=\"Group\".\"ID\" AND \"Group_Members\".\"MemberID\" = $member->ID")
            ->innerJoin('Permission', "\"Group\".\"ID\"=\"Permission\".\"GroupID\" AND \"Permission\".\"Code\" IN ($SQL_codes, 'CMS_ACCESS_LeftAndMain', 'ADMIN')");

        /** @skipUpgrade */
        $rolesSubsites = DataList::create(static::class)
            ->leftJoin('Group_Subsites', "\"Group_Subsites\".\"SubsiteID\" = \"Subsite\".\"ID\"")
            ->innerJoin('Group', "\"Group\".\"ID\" = \"Group_Subsites\".\"GroupID\" OR \"Group\".\"AccessAllSubsites\" = 1")
            ->innerJoin('Group_Members', "\"Group_Members\".\"GroupID\"=\"Group\".\"ID\" AND \"Group_Members\".\"MemberID\" = $member->ID")
            ->innerJoin('Group_Roles', "\"Group_Roles\".\"GroupID\"=\"Group\".\"ID\"")
            ->innerJoin('PermissionRole', "\"Group_Roles\".\"PermissionRoleID\"=\"PermissionRole\".\"ID\"")
            ->innerJoin('PermissionRoleCode', "\"PermissionRole\".\"ID\"=\"PermissionRoleCode\".\"RoleID\" AND \"PermissionRoleCode\".\"Code\" IN ($SQL_codes, 'CMS_ACCESS_LeftAndMain', 'ADMIN')");

        if (!$subsites->count() && $rolesSubsites->count()) {
            self::$_cache_accessible_sites[$cacheKey] = $rolesSubsites;
            return $rolesSubsites;
        }

        $subsites = new ArrayList($subsites->toArray());

        if ($rolesSubsites) {
            foreach ($rolesSubsites as $subsite) {
                if (!$subsites->find('ID', $subsite->ID)) {
                    $subsites->push($subsite);
                }
            }
        }

        self::$_cache_accessible_sites[$cacheKey] = $subsites;
        return $subsites;
    }

    /**
     * Return the subsites that the current user can access by given permission.
     * Sites will only be included if they have a Title.
     *
     * @param $permCode array|string Either a single permission code or an array of permission codes.
     * @param $member
     * @return Array of subsite IDs
     */
    public static function accessible_sites_ids($permCode, $member = null)
    {
        // Rationalise member arguments
        if (!$member) {
            $member = Security::getCurrentUser();
        }
        if (!$member) {
            return [];
        }
        if (!is_object($member)) {
            $member = DataObject::get_by_id(Member::class, $member);
        }

        // Rationalise permCode argument
        if (is_array($permCode)) {
            $SQL_codes = "'" . implode("', '", Convert::raw2sql($permCode)) . "'";
        } else {
            $SQL_codes = "'" . Convert::raw2sql($permCode) . "'";
        }

        // Cache handling
        $cacheKey = $SQL_codes . '-' . $member->ID;
        if (!isset(self::$_cache_accessible_sites_ids[$cacheKey])) {
            self::$_cache_accessible_sites_ids[$cacheKey] = self::accessible_sites($permCode, $member)->column('ID');
        }
        return self::$_cache_accessible_sites_ids[$cacheKey];
    }

    /**
     * Checks if a member can be granted certain permissions, regardless of the subsite context.
     * Similar logic to {@link Permission::checkMember()}, but only returns TRUE
     * if the member is part of a group with the "AccessAllSubsites" flag set.
     * If more than one permission is passed to the method, at least one of them must
     * be granted for if to return TRUE.
     *
     * @todo Allow permission inheritance through group hierarchy.
     *
     * @param Member Member to check against. Defaults to currently logged in member
     * @param Array Permission code strings. Defaults to "ADMIN".
     * @return boolean
     */
    public static function hasMainSitePermission($member = null, $permissionCodes = ['ADMIN'])
    {
        if (!is_array($permissionCodes)) {
            user_error('Permissions must be passed to Subsite::hasMainSitePermission as an array', E_USER_ERROR);
        }

        if (!$member && $member !== false) {
            $member = Security::getCurrentUser();
        }

        if (!$member) {
            return false;
        }

        if (!in_array("ADMIN", $permissionCodes)) {
            $permissionCodes[] = "ADMIN";
        }

        $SQLa_perm = Convert::raw2sql($permissionCodes);
        $SQL_perms = join("','", $SQLa_perm);
        $memberID = (int)$member->ID;

        // Count this user's groups which can access the main site
        $groupCount = DB::query("
            SELECT COUNT(\"Permission\".\"ID\")
            FROM \"Permission\"
            INNER JOIN \"Group\" ON \"Group\".\"ID\" = \"Permission\".\"GroupID\" AND \"Group\".\"AccessAllSubsites\" = 1
            INNER JOIN \"Group_Members\" ON \"Group_Members\".\"GroupID\" = \"Permission\".\"GroupID\"
            WHERE \"Permission\".\"Code\" IN ('$SQL_perms')
            AND \"MemberID\" = {$memberID}
        ")->value();

        // Count this user's groups which have a role that can access the main site
        $roleCount = DB::query("
            SELECT COUNT(\"PermissionRoleCode\".\"ID\")
            FROM \"Group\"
            INNER JOIN \"Group_Members\" ON \"Group_Members\".\"GroupID\" = \"Group\".\"ID\"
            INNER JOIN \"Group_Roles\" ON \"Group_Roles\".\"GroupID\"=\"Group\".\"ID\"
            INNER JOIN \"PermissionRole\" ON \"Group_Roles\".\"PermissionRoleID\"=\"PermissionRole\".\"ID\"
            INNER JOIN \"PermissionRoleCode\" ON \"PermissionRole\".\"ID\"=\"PermissionRoleCode\".\"RoleID\"
            WHERE \"PermissionRoleCode\".\"Code\" IN ('$SQL_perms')
            AND \"Group\".\"AccessAllSubsites\" = 1
            AND \"MemberID\" = {$memberID}
        ")->value();

        // There has to be at least one that allows access.
        return ($groupCount + $roleCount > 0);
    }

    /**
     *
     * @var array
     */
    private static $db = [
        'Title' => 'Varchar(255)',
        'Language' => 'Varchar(6)',
        'Domain' => 'Varchar(255)',
    ];

    /**
     *
     * @var array
     */
    private static $belongs_many_many = [
        "Groups" => "SilverStripe\\Security\\Group",
    ];

    /**
     *
     * @var array
     */
    private static $defaults = [
        'Language' => '',
    ];

    /**
     *
     * @var array
     */
    private static $searchable_fields = [
        'Title',
        'Domain',
    ];

    /**
     *
     * @var string
     */
    private static $default_sort = "\"Title\" ASC";

    /**
     * Show the configuration fields for each subsite
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        $languageSelector = new TextField(
            'Language',
            $this->fieldLabel('Language')
        );

        $pageTypeMap = [];
        $pageTypes = SiteTree::page_type_classes();
        foreach ($pageTypes as $pageType) {
            $pageTypeMap[$pageType] = singleton($pageType)->i18n_singular_name();
        }
        asort($pageTypeMap);

        $fields = new FieldList(
            $subsiteTabs = new TabSet(
                'Root',
                new Tab(
                    'Configuration',
                    _t('Subsite.TabTitleConfig', 'Configuration'),
                    new HeaderField('ConfigHeading', 'Subsite configuration', 2),
                    new TextField('Title', $this->fieldLabel('Title'), $this->Title),
                    new TextField('Domain', $this->fieldLabel('Domain')),
                    $languageSelector
                )
            ),
            new HiddenField('ID', '', $this->ID),
            new HiddenField('IsSubsite', '', 1)
        );

        $subsiteTabs->addExtraClass('subsite-model');

        $this->extend('updateCMSFields', $fields);
        return $fields;
    }

    /**
     *
     * @param boolean $includerelations
     * @return array
     */
    public function fieldLabels($includerelations = true)
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Title'] = _t('Subsites.TitleFieldLabel', 'Subsite Name');
        $labels['Language'] = _t('Subsites.LanguageFieldLabel', 'Language');
        $labels['Domain'] = _t('Subsites.DomainFieldLabel', 'Domain');
        return $labels;
    }

    /**
     *
     * @return ValidationResult
     */
    public function validate()
    {
        $result = parent::validate();
        if (!$this->Title) {
            $result->error(_t('Subsite.ValidateTitle', 'Please add a "Title"'));
        }
        return $result;
    }

    /**
     * Get the absolute URL for this subsite
     * @return string
     */
    public function absoluteBaseURL()
    {
        return Controller::join_links(
            Director::protocol() . $this->Domain,
            Director::baseURL()
        );
    }

    /**
     * @todo getClassName is redundant, already stored as a database field?
     */
    public function getClassName()
    {
        return get_class($this);
    }

    /**
     * Make this subsite the current one
     */
    public function activate()
    {
        Subsite::changeSubsite($this);
    }

    /**
     * @param Member $member
     * @return boolean
     */
    public function canEdit($member = null)
    {
        return Permission::check(['EDIT_SITECONFIG', 'ADMIN'], 'any', $member);
    }

    public function canView($member = null)
    {
        $member = $member ?: Security::getCurrentUser();
        if (!$member) {
            return false;
        }
        foreach ($member->Groups() as $group) {
            if ($group->AccessAllSubsites == 1) {
                return true;
            }
            foreach ($group->Subsites() as $subsite) {
                if ($subsite->ID == $this->ID) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     *
     * @param array $permissionCodes
     * @return DataList
     */
    public function getMembersByPermission($permissionCodes = ['ADMIN'])
    {
        if (!is_array($permissionCodes)) {
            user_error('Permissions must be passed to Subsite::getMembersByPermission as an array', E_USER_ERROR);
        }
        $SQL_permissionCodes = Convert::raw2sql($permissionCodes);

        $SQL_permissionCodes = join("','", $SQL_permissionCodes);

        return DataObject::get(
            Member::class,
            "\"Group\".\"SubsiteID\" = $this->ID AND \"Permission\".\"Code\" IN ('$SQL_permissionCodes')",
            '',
            "LEFT JOIN \"Group_Members\" ON \"Member\".\"ID\" = \"Group_Members\".\"MemberID\"
            LEFT JOIN \"Group\" ON \"Group\".\"ID\" = \"Group_Members\".\"GroupID\"
            LEFT JOIN \"Permission\" ON \"Permission\".\"GroupID\" = \"Group\".\"ID\""
        );
    }
}
