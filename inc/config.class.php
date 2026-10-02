<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginKanbanConfig extends CommonGLPI {

   public static $rightname = 'config';

   /** Configuration context used in the glpi_configs table */
   const CONFIG_CONTEXT = 'plugin:kanban';

   /**
    * Load config stored through GLPI's configuration API.
    * Returns defaults when nothing is stored yet.
    */
   public static function load(): array {
      $defaults = [
         'refresh_options'          => [1, 2, 3],
         'status_order'             => [],
         'enable_drag_drop'         => true,
         'require_pending_reason'   => false,
         'require_solution_model'   => false,
         'require_solution_type'    => false,
         'cards_per_column'         => 50,
         'wip_limit'                => 0,
         'wip_block_exceed'         => false,
         'enable_undo'              => true,
      ];
      $stored = \Config::getConfigurationValues(self::CONFIG_CONTEXT);
      $cfg = [];
      foreach (array_keys($defaults) as $key) {
         if (isset($stored[$key])) {
            // GLPI stores config values as strings; arrays are serialized
            // (exportArrayToDB). Scalars (like the drag & drop flag) stay plain.
            $value = $stored[$key];
            $cfg[$key] = (is_array($value)
               || (is_string($value) && (str_starts_with($value, '[') || str_starts_with($value, 'a:'))))
               ? importArrayFromDB($value)
               : $value;
         }
      }
      return array_merge($defaults, $cfg);
   }

   /**
    * Persist config through GLPI's configuration API.
    * Array values are serialized (exportArrayToDB) because the DB column is a string.
    */
   public static function save(array $data): bool {
      $to_store = [];
      foreach ($data as $key => $value) {
         $to_store[$key] = is_array($value) ? exportArrayToDB($value) : $value;
      }
      \Config::setConfigurationValues(self::CONFIG_CONTEXT, $to_store);
      return true;
   }

   /**
    * Get available refresh options (in minutes).
    */
   public static function getRefreshOptions(): array {
      $cfg = self::load();
      return $cfg['refresh_options'] ?? [1, 2, 3];
   }

   /**
    * Get the configured column (status) display order, if any.
    * Empty array means "follow the native status order".
    */
   public static function getStatusOrder(): array {
      $cfg = self::load();
      $stored = $cfg['status_order'] ?? [];
      if (!is_array($stored)) {
         return [];
      }
      $order = array_filter(array_map('intval', $stored), function ($v) {
         return $v > 0;
      });
      return array_values(array_unique($order));
   }

   /**
    * Whether the board's drag & drop is enabled.
    */
   public static function getEnableDragDrop(): bool {
      $cfg = self::load();
      return !empty($cfg['enable_drag_drop']);
   }

   /**
    * Whether a pending reason must be selected when moving a card to Pending.
    */
   public static function getRequirePendingReason(): bool {
      $cfg = self::load();
      return !empty($cfg['require_pending_reason']);
   }

   /**
    * Whether a solution model must be selected when moving a card to Solved.
    */
   public static function getRequireSolutionModel(): bool {
      $cfg = self::load();
      return !empty($cfg['require_solution_model']);
   }

   /**
    * Whether a solution type must be set when moving a card to Solved.
    */
   public static function getRequireSolutionType(): bool {
      $cfg = self::load();
      return !empty($cfg['require_solution_type']);
   }

   /**
    * How many cards a column loads before a "load more" is needed (1-200).
    */
   public static function getCardsPerColumn(): int {
      $cfg = self::load();
      $value = (int)($cfg['cards_per_column'] ?? 50);
      if ($value < 1) {
         return 1;
      }
      return min(200, $value);
   }

   /**
    * Work-in-progress limit per column (0 = no limit).
    */
   public static function getWipLimit(): int {
      $cfg = self::load();
      $value = (int)($cfg['wip_limit'] ?? 0);
      return $value > 0 ? min(10000, $value) : 0;
   }

   /**
    * Whether reaching the work-in-progress limit only warns (false) or refuses
    * the move (true).
    */
   public static function getWipBlockExceed(): bool {
      $cfg = self::load();
      return !empty($cfg['wip_block_exceed']);
   }

   /**
    * Whether the board offers to undo a card move.
    */
   public static function getEnableUndo(): bool {
      $cfg = self::load();
      return !empty($cfg['enable_undo']);
   }

   public static function getTypeName($nb = 0) {
      return __('Kanban Configuration', 'kanban');
   }

   public static function getIcon() {
      return 'ti ti-settings';
   }

   /**
    * Read a boolean switch coming from the configuration form.
    */
   private static function readSwitch(string $key): bool {
      return isset($_POST[$key]) && in_array($_POST[$key], ['1', 'on', 'true'], true);
   }

   public function display($options = []) {
      global $CFG_GLPI;

      $success = false;
      $error = '';

      if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kanban_config'])) {
         // CSRF is enforced by GLPI core before this script runs, on every POST:
         //   - GLPI 10: inc/includes.php calls Session::checkCSRF($_POST);
         //   - GLPI 11: CheckCsrfListener calls Session::checkCSRF($request->request).
         // Session::validateCSRF() *consumes* the token (unset from
         // $_SESSION['glpicsrftokens']) unless GLPI_KEEP_CSRF_TOKEN is defined,
         // and that constant is only defined for /ajax/ routes. Verified in the
         // upstream sources: this is already the case at tag 10.0.0, so a second
         // validation here could only ever fail and the form could never be saved.
         $refresh_raw = $_POST['refresh_options'] ?? '';
         $refresh_values = array_filter(
            array_map('intval', array_map('trim', explode(',', $refresh_raw)))
         );
         $refresh_values = array_unique(array_filter($refresh_values, function ($v) {
            return $v >= 1 && $v <= 60;
         }));
         if (empty($refresh_values)) {
            $refresh_values = [1, 2, 3];
         }
         sort($refresh_values);

         $order_raw = $_POST['status_order'] ?? '';
         $order_values = array_filter(
            array_map('intval', array_map('trim', explode(',', $order_raw)))
         );
         $order_values = array_values(array_unique(array_filter($order_values, function ($v) {
            return $v > 0;
         })));

         // Clamped here as well as on read: a hand-built POST cannot store an
         // out-of-range value that the board would then have to defend against.
         $cards_per_column = (int)($_POST['cards_per_column'] ?? 50);
         $cards_per_column = min(200, max(1, $cards_per_column));

         $wip_limit = (int)($_POST['wip_limit'] ?? 0);
         $wip_limit = $wip_limit > 0 ? min(10000, $wip_limit) : 0;

         $success = self::save([
            'refresh_options'        => array_values($refresh_values),
            'status_order'           => $order_values,
            'enable_drag_drop'       => self::readSwitch('enable_drag_drop') ? 1 : 0,
            'require_pending_reason' => self::readSwitch('require_pending_reason') ? 1 : 0,
            'require_solution_model' => self::readSwitch('require_solution_model') ? 1 : 0,
            'require_solution_type'  => self::readSwitch('require_solution_type') ? 1 : 0,
            'cards_per_column'       => $cards_per_column,
            'wip_limit'              => $wip_limit,
            'wip_block_exceed'       => self::readSwitch('wip_block_exceed') ? 1 : 0,
            'enable_undo'            => self::readSwitch('enable_undo') ? 1 : 0,
         ]);
         if (!$success) {
            $error = __('Could not save configuration', 'kanban');
         }
      }

      $cfg = self::load();
      $refresh_str = implode(', ', $cfg['refresh_options'] ?? [1, 2, 3]);
      $order_str   = implode(', ', $cfg['status_order'] ?? []);
      $drag_enabled = !empty($cfg['enable_drag_drop']);
      $require_pending = !empty($cfg['require_pending_reason']);
      $require_model   = !empty($cfg['require_solution_model']);
      $require_type    = !empty($cfg['require_solution_type']);
      $cards_per_column = self::getCardsPerColumn();
      $wip_limit        = self::getWipLimit();
      $wip_block        = !empty($cfg['wip_block_exceed']);
      $undo_enabled     = !empty($cfg['enable_undo']);

      $self_url = htmlspecialchars($_SERVER['REQUEST_URI']);
      ?>

      <div class="container-fluid py-4" style="max-width: 720px;">
         <h3 class="mb-3">
            <i class="ti ti-settings"></i>
            <?php echo __('Kanban Plugin Configuration', 'kanban'); ?>
         </h3>

         <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
               <i class="ti ti-check me-1"></i>
               <?php echo __('Configuration saved successfully.', 'kanban'); ?>
               <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
         <?php endif; ?>

         <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
               <i class="ti ti-alert-triangle me-1"></i>
               <?php echo htmlspecialchars($error); ?>
               <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
         <?php endif; ?>

         <?php
         // One form for the whole configuration: the settings used to live in
         // four separate forms, each repeating every other setting as a hidden
         // field, so adding one meant editing four places and a forgotten
         // hidden field silently reset the setting. A checkbox that is not
         // submitted means "off", which a single form gets right by itself.
         ?>
         <form method="post" action="<?php echo $self_url; ?>">
            <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
            <input type="hidden" name="kanban_config" value="1">

            <div class="card">
               <div class="card-header">
                  <i class="ti ti-clock-play me-1"></i>
                  <?php echo __('Auto-refresh', 'kanban'); ?>
               </div>
               <div class="card-body">
                  <div class="mb-3">
                     <label for="refresh_options" class="form-label fw-semibold">
                        <?php echo __('Available refresh intervals (minutes)', 'kanban'); ?>
                     </label>
                     <input type="text"
                            class="form-control"
                            id="refresh_options"
                            name="refresh_options"
                            value="<?php echo htmlspecialchars($refresh_str); ?>"
                            placeholder="1, 2, 3">
                     <div class="form-text">
                        <?php echo __('Comma-separated values in minutes (1-60). These intervals will be available in the auto-refresh dropdown on the Kanban board.', 'kanban'); ?>
                     </div>
                  </div>

                  <div class="mb-3">
                     <label for="cards_per_column" class="form-label fw-semibold">
                        <?php echo __('Cards loaded per column', 'kanban'); ?>
                     </label>
                     <input type="number"
                            class="form-control"
                            id="cards_per_column"
                            name="cards_per_column"
                            min="1"
                            max="200"
                            value="<?php echo $cards_per_column; ?>">
                     <div class="form-text">
                        <?php echo __('How many cards a column shows before a "load more" button appears (1-200). A column that matches more tickets than this displays its real total, so nothing is hidden.', 'kanban'); ?>
                     </div>
                  </div>

                  <div class="form-check form-switch mb-3">
                     <input class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="enable_drag_drop"
                            name="enable_drag_drop"
                            value="1"
                            <?php echo $drag_enabled ? 'checked' : ''; ?>>
                     <label class="form-check-label fw-semibold" for="enable_drag_drop">
                        <?php echo __('Enable moving cards between columns by drag & drop', 'kanban'); ?>
                     </label>
                     <div class="form-text">
                        <?php echo __('When moving to Pending the reason is stored as a follow-up; when moving to Solved a solution (description, model or type) can be recorded.', 'kanban'); ?>
                     </div>
                  </div>
               </div>
            </div>

            <div class="card mt-3">
               <div class="card-header">
                  <i class="ti ti-columns-3 me-1"></i>
                  <?php echo __('Column order', 'kanban'); ?>
               </div>
               <div class="card-body">
                  <label for="status_order" class="form-label fw-semibold">
                     <?php echo __('Order of the board columns (status ids)', 'kanban'); ?>
                  </label>
                  <input type="text"
                         class="form-control"
                         id="status_order"
                         name="status_order"
                         value="<?php echo htmlspecialchars($order_str); ?>"
                         placeholder="1, 2, 3, 4, 5, 6">
                  <div class="form-text">
                     <?php echo __('Comma-separated ticket status ids, in the order the columns should appear. Leave empty to follow the native status order. Typically: 1 (New), 2 (Assigned), 3 (Planned), 4 (Pending), 5 (Solved), 6 (Closed).', 'kanban'); ?>
                  </div>
               </div>
            </div>

            <div class="card mt-3">
               <div class="card-header">
                  <i class="ti ti-asterisk me-1"></i>
                  <?php echo __('Required fields on column changes', 'kanban'); ?>
               </div>
               <div class="card-body">
                  <div class="form-check form-switch mb-3">
                     <input class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="require_pending_reason"
                            name="require_pending_reason"
                            value="1"
                            <?php echo $require_pending ? 'checked' : ''; ?>>
                     <label class="form-check-label fw-semibold" for="require_pending_reason">
                        <?php echo __('Require a pending reason when moving a card to Pending', 'kanban'); ?>
                     </label>
                     <div class="form-text">
                        <?php echo __('The move is blocked until a pending reason is selected.', 'kanban'); ?>
                     </div>
                  </div>

                  <div class="form-check form-switch mb-3">
                     <input class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="require_solution_model"
                            name="require_solution_model"
                            value="1"
                            <?php echo $require_model ? 'checked' : ''; ?>>
                     <label class="form-check-label fw-semibold" for="require_solution_model">
                        <?php echo __('Require a solution model when moving a card to Solved', 'kanban'); ?>
                     </label>
                     <div class="form-text">
                        <?php echo __('The move is blocked until a solution model is selected.', 'kanban'); ?>
                     </div>
                  </div>

                  <div class="form-check form-switch mb-3">
                     <input class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="require_solution_type"
                            name="require_solution_type"
                            value="1"
                            <?php echo $require_type ? 'checked' : ''; ?>>
                     <label class="form-check-label fw-semibold" for="require_solution_type">
                        <?php echo __('Require a solution type when moving a card to Solved', 'kanban'); ?>
                     </label>
                     <div class="form-text">
                        <?php echo __('The move is blocked until a solution type is set (either chosen or filled in automatically from the model).', 'kanban'); ?>
                     </div>
                  </div>
               </div>
            </div>

            <div class="card mt-3">
               <div class="card-header">
                  <i class="ti ti-gauge me-1"></i>
                  <?php echo __('Work in progress limit', 'kanban'); ?>
               </div>
               <div class="card-body">
                  <div class="mb-3">
                     <label for="wip_limit" class="form-label fw-semibold">
                        <?php echo __('Maximum tickets in progress per column', 'kanban'); ?>
                     </label>
                     <input type="number"
                            class="form-control"
                            id="wip_limit"
                            name="wip_limit"
                            min="0"
                            max="10000"
                            value="<?php echo $wip_limit; ?>">
                     <div class="form-text">
                        <?php echo __('Zero disables the limit. The count is the tickets you can see in the column, ignoring the active filters, because the limit applies to the queue and not to the current view.', 'kanban'); ?>
                     </div>
                  </div>

                  <div class="form-check form-switch mb-3">
                     <input class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="wip_block_exceed"
                            name="wip_block_exceed"
                            value="1"
                            <?php echo $wip_block ? 'checked' : ''; ?>>
                     <label class="form-check-label fw-semibold" for="wip_block_exceed">
                        <?php echo __('Refuse moves that would exceed the limit', 'kanban'); ?>
                     </label>
                     <div class="form-text">
                        <?php echo __('When off, a column over the limit is only highlighted on the board. When on, the move is rejected and the check also runs on the server.', 'kanban'); ?>
                     </div>
                  </div>
               </div>
            </div>

            <div class="card mt-3">
               <div class="card-header">
                  <i class="ti ti-arrow-back-up me-1"></i>
                  <?php echo __('Undo a card move', 'kanban'); ?>
               </div>
               <div class="card-body">
                  <div class="form-check form-switch mb-3">
                     <input class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="enable_undo"
                            name="enable_undo"
                            value="1"
                            <?php echo $undo_enabled ? 'checked' : ''; ?>>
                     <label class="form-check-label fw-semibold" for="enable_undo">
                        <?php echo __('Offer to undo a card move from the board', 'kanban'); ?>
                     </label>
                     <div class="form-text">
                        <?php echo __('A notification lets you put the ticket back in its previous column. Undoing only restores the status: a pending reason or a solution recorded by the move stays in the ticket history.', 'kanban'); ?>
                     </div>
                  </div>
               </div>
            </div>

            <div class="mt-3">
               <button type="submit" class="btn btn-primary">
                  <i class="ti ti-device-floppy me-1"></i>
                  <?php echo __('Save', 'kanban'); ?>
               </button>
            </div>
         </form>
      </div>
      <?php
      return true;
   }
}
