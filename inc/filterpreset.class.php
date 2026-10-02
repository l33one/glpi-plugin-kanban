<?php

/**
 * -------------------------------------------------------------------------
 * GLPI Kanban Plugin
 * -------------------------------------------------------------------------
 *
 * @package   Plugin Kanban
 * @author    Leewan Meneses
 * @license   GPL-2.0+
 * @since     2026
 *
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Saved board views: the active filters, stored server-side per user.
 *
 * Filters used to live only in the browser (localStorage), which meant the same
 * "my tickets, urgent only" view had to be rebuilt on every machine and was
 * lost when the browser storage was cleared. A preset is a row, so it follows
 * the user, and being a CommonDBTM it is covered by GLPI's usual
 * right/rightname model.
 */
class PluginKanbanFilterPreset extends CommonDBTM {

   /** Filter fields a preset may carry, with the cast used on save. */
   const FIELDS = [
      'search'         => 'string',
      'technician'     => 'int',
      'requester'      => 'int',
      'group'          => 'int',
      'type'           => 'int',
      'category'       => 'int',
      'assigned_to_me' => 'bool',
   ];

   public static $rightname = 'plugin_kanban';

   public static $canMake = false;

   public static $canView = 1;

   public static $canCreate = 1;

   public static $canUpdate = 1;

   public static $canDelete = 1;

   public static $canPurge = 1;

   public static function getTypeName($nb = 0) {
      return _n('Saved board view', 'Saved board views', $nb, 'kanban');
   }

   public static function getIcon() {
      return 'ti ti-bookmarks';
   }

   public static function getTable($classname = null) {
      return 'glpi_kanban_filter_presets';
   }

   /**
    * Presets owned by a user, plus the ones their profiles may share.
    */
   public static function listForUser(): array {
      global $DB;

      $user_id = (int)Session::getLoginUserID();
      if ($user_id <= 0) {
         return [];
      }

      $criteria = [
         'SELECT'    => [
            'glpi_kanban_filter_presets.*',
            'glpi_users.name AS user_name',
         ],
         'FROM'      => 'glpi_kanban_filter_presets',
'LEFT JOIN' => [
'glpi_users' => [
                  'FKEY' => [
                     'glpi_kanban_filter_presets' => 'users_id',
                     'glpi_users'                  => 'id',
                  ],
               ],
          ],
'WHERE'     => [
               [
                  'AND' => [
                     // A preset whose owner is gone can only ever be edited by
                     // an administrator (see isEditableBy()), so listing it to
                     // everybody else just offers a delete that is refused.
                     ['NOT' => ['glpi_kanban_filter_presets.users_id' => 0]],
                     [
                        'OR' => [
                           ['glpi_kanban_filter_presets.users_id' => $user_id],
                           ['glpi_kanban_filter_presets.is_private' => 0],
                        ],
                     ],
                  ],
               ],
          ],
         'ORDER'     => 'glpi_kanban_filter_presets.name ASC',
      ];

      $out = [];
      foreach ($DB->request($criteria) as $row) {
         $owner_id = (int)$row['users_id'];

         $out[] = [
            'id'         => (int)$row['id'],
            'name'       => $row['name'],
            'filters'    => self::decodeFilters($row['filters']),
            'is_private' => (int)$row['is_private'] === 1,
            'user_name'  => $row['user_name'],
            'can_write'  => self::isEditableBy($owner_id),
         ];
      }

      return $out;
   }

   /**
    * Create or replace a preset owned by the current user.
    *
    * @param string     $name
    * @param array      $filters
    * @param int|null   $id       Existing preset to overwrite, or null to create.
    * @param bool       $is_private
    * @return array ['success' => bool, 'error' => string|null, 'id' => int|null]
    */
   public function saveForUser(string $name, array $filters, ?int $id = null, bool $is_private = true): array {
      $user_id = (int)Session::getLoginUserID();
      if ($user_id <= 0 || !self::canManageViews()) {
         return ['success' => false, 'error' => __('Permission denied', 'kanban')];
      }

      $name = trim(preg_replace('/\s+/u', ' ', $name));
      if ($name === '') {
         return ['success' => false, 'error' => __('Please give the view a name', 'kanban')];
      }
      if (mb_strlen($name) > 100) {
         return ['success' => false, 'error' => __('The view name is too long', 'kanban')];
      }

      // Shared views are only for users allowed to make them available to
      // everyone, otherwise "shared" would be a privilege nobody granted.
      $is_private = $is_private || !Session::haveRight('config', UPDATE);

      $input = [
         'name'       => $name,
         'filters'    => exportArrayToDB(self::sanitizeFilters($filters)),
         'is_private' => $is_private ? 1 : 0,
         'users_id'   => $user_id,
      ];

      if ($id !== null && $id > 0) {
         if (!$this->getFromDB($id) || !self::isEditableBy((int)$this->fields['users_id'])) {
            return ['success' => false, 'error' => __('Permission denied', 'kanban')];
         }
         $saved = $this->update(['id' => $id] + $input);

         return [
            'success' => (bool)$saved,
            'error'   => $saved ? null : __('Could not save the view', 'kanban'),
            'id'      => $saved ? $id : null,
         ];
      }

      $new_id = $this->add($input);
      if ($new_id === false) {
         return ['success' => false, 'error' => __('Could not save the view', 'kanban')];
      }

      return ['success' => true, 'error' => null, 'id' => (int)$new_id];
   }

   public function renameForUser(int $id, string $name): array {
      if ($id <= 0 || !$this->getFromDB($id)) {
         return ['success' => false, 'error' => __('View not found', 'kanban')];
      }
      if (!self::isEditableBy((int)$this->fields['users_id'])) {
         return ['success' => false, 'error' => __('Permission denied', 'kanban')];
      }

      $name = trim(preg_replace('/\s+/u', ' ', $name));
      if ($name === '' || mb_strlen($name) > 100) {
         return ['success' => false, 'error' => __('Invalid view name', 'kanban')];
      }

      $ok = $this->update(['id' => $id, 'name' => $name]);

      return [
         'success' => (bool)$ok,
         'error'   => $ok ? null : __('Could not rename the view', 'kanban'),
      ];
   }

   public function deleteForUser(int $id): array {
      if ($id <= 0 || !$this->getFromDB($id)) {
         return ['success' => false, 'error' => __('View not found', 'kanban')];
      }
      if (!self::isEditableBy((int)$this->fields['users_id'])) {
         return ['success' => false, 'error' => __('Permission denied', 'kanban')];
      }

      $ok = $this->delete(['id' => $id], true);

      return [
         'success' => (bool)$ok,
         'error'   => $ok ? null : __('Could not delete the view', 'kanban'),
      ];
   }

   /**
    * Whether the current user may create, rename or delete their own views.
    *
    * A preset holds nothing privileged: the filters a user saves are the ones
    * they can type into the board form themselves, and the row is only ever
    * read back by its owner. Tying this to the right that opens the board keeps
    * saved views working for the profiles the plugin is granted to, instead of
    * silently failing for everyone who is not a super-admin. Sharing a view with
    * everybody is a different matter and stays with the administrators
    * (see saveForUser()).
    */
   public static function canManageViews(): bool {
      return (int)Session::getLoginUserID() > 0 && Session::haveRight(self::$rightname, READ);
   }

   /**
    * A preset belongs to the user who saved it. Shared views are curated by an
    * administrator: someone who merely applies a colleague's view must not be
    * able to rewrite it for everybody.
    */
   public static function isEditableBy(int $owner_id): bool {
      $user_id = (int)Session::getLoginUserID();

      if ($owner_id === $user_id) {
         return self::canManageViews();
      }

      return Session::haveRight('config', UPDATE);
   }

   /**
    * Keep only the known fields, with their declared type.
    */
   private static function sanitizeFilters(array $filters): array {
      $clean = [];
      foreach (self::FIELDS as $field => $cast) {
         if (!array_key_exists($field, $filters)) {
            continue;
         }
         $value = $filters[$field];

         switch ($cast) {
            case 'int':
               $clean[$field] = ($value === null || $value === '') ? null : (int)$value;
               break;

            case 'bool':
               $clean[$field] = (int)(bool)$value;
               break;

            default:
               $value = is_scalar($value) ? trim((string)$value) : '';
               $clean[$field] = mb_substr($value, 0, 255);
         }
      }

      return $clean;
   }

   private static function decodeFilters($stored): array {
      if (is_array($stored)) {
         return $stored;
      }
      if (!is_string($stored) || $stored === '') {
         return [];
      }

      $decoded = importArrayFromDB($stored);
      return is_array($decoded) ? $decoded : [];
   }
}