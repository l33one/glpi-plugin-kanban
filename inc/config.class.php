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

   public static function getTypeName($nb = 0) {
      return __('Kanban Configuration', 'kanban');
   }

   public static function getIcon() {
      return 'ti ti-settings';
   }

   public function display($options = []) {
      global $CFG_GLPI;

      $success = false;
      $error = '';

      if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kanban_config'])) {
         // GLPI >= 10.0.8 (and GLPI 11) validate AND consume the single-use CSRF
         // token in core (inc/includes.php / CheckCsrfListener) before this script
         // runs, so re-checking here would always fail ("Invalid CSRF token") and
         // the config could never be saved. On older GLPI we validate as usual.
         $csrfOk = true;
         if (version_compare(GLPI_VERSION, '10.0.8', '<')) {
            $csrfOk = Session::validateCSRF($_POST);
         }

         if (!$csrfOk) {
            $error = __('Invalid CSRF token', 'kanban');
         } else {
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

            // Checkbox present (checked => "on") or hidden pre-filled value.
            $drag = isset($_POST['enable_drag_drop'])
               && in_array($_POST['enable_drag_drop'], ['1', 'on', 'true'], true);
            $require_pending = isset($_POST['require_pending_reason'])
               && in_array($_POST['require_pending_reason'], ['1', 'on', 'true'], true);
            $require_model = isset($_POST['require_solution_model'])
               && in_array($_POST['require_solution_model'], ['1', 'on', 'true'], true);
            $require_type = isset($_POST['require_solution_type'])
               && in_array($_POST['require_solution_type'], ['1', 'on', 'true'], true);

            $success = self::save([
               'refresh_options'        => array_values($refresh_values),
               'status_order'           => $order_values,
               'enable_drag_drop'       => $drag ? 1 : 0,
               'require_pending_reason' => $require_pending ? 1 : 0,
               'require_solution_model' => $require_model ? 1 : 0,
               'require_solution_type'  => $require_type ? 1 : 0,
            ]);
            if (!$success) {
               $error = __('Could not save configuration file', 'kanban');
            }
         }
      }

      $cfg = self::load();
      $refresh_str = implode(', ', $cfg['refresh_options'] ?? [1, 2, 3]);
      $order_str   = implode(', ', $cfg['status_order'] ?? []);
      $drag_enabled = !empty($cfg['enable_drag_drop']);
      $require_pending = !empty($cfg['require_pending_reason']);
      $require_model   = !empty($cfg['require_solution_model']);
      $require_type    = !empty($cfg['require_solution_type']);
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

         <div class="card">
            <div class="card-header">
               <i class="ti ti-clock-play me-1"></i>
               <?php echo __('Auto-refresh', 'kanban'); ?>
            </div>
            <div class="card-body">
               <form method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                  <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
                  <input type="hidden" name="kanban_config" value="1">
                  <?php echo Html::hidden('enable_drag_drop', ['value' => $drag_enabled ? 1 : 0]); ?>
                  <?php echo Html::hidden('status_order', ['value' => $order_str]); ?>
                  <?php echo Html::hidden('require_pending_reason', ['value' => $require_pending ? 1 : 0]); ?>
                  <?php echo Html::hidden('require_solution_model', ['value' => $require_model ? 1 : 0]); ?>
                  <?php echo Html::hidden('require_solution_type', ['value' => $require_type ? 1 : 0]); ?>

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

                  <button type="submit" class="btn btn-primary">
                     <i class="ti ti-device-floppy me-1"></i>
                     <?php echo __('Save', 'kanban'); ?>
                  </button>
               </form>
            </div>
         </div>

         <div class="card mt-3">
            <div class="card-header">
               <i class="ti ti-columns-3 me-1"></i>
               <?php echo __('Column order', 'kanban'); ?>
            </div>
            <div class="card-body">
               <form method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                  <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
                  <input type="hidden" name="kanban_config" value="1">
                  <?php echo Html::hidden('enable_drag_drop', ['value' => $drag_enabled ? 1 : 0]); ?>
                  <?php echo Html::hidden('refresh_options', ['value' => $refresh_str]); ?>
                  <?php echo Html::hidden('require_pending_reason', ['value' => $require_pending ? 1 : 0]); ?>
                  <?php echo Html::hidden('require_solution_model', ['value' => $require_model ? 1 : 0]); ?>
                  <?php echo Html::hidden('require_solution_type', ['value' => $require_type ? 1 : 0]); ?>

                  <div class="mb-3">
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

                  <button type="submit" class="btn btn-primary">
                     <i class="ti ti-device-floppy me-1"></i>
                     <?php echo __('Save', 'kanban'); ?>
                  </button>
               </form>
            </div>
         </div>

         <div class="card mt-3">
            <div class="card-header">
               <i class="ti ti-hand-grab me-1"></i>
               <?php echo __('Drag & drop', 'kanban'); ?>
            </div>
            <div class="card-body">
               <form method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                  <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
                  <input type="hidden" name="kanban_config" value="1">
                  <?php echo Html::hidden('refresh_options', ['value' => $refresh_str]); ?>
                  <?php echo Html::hidden('status_order', ['value' => $order_str]); ?>
                  <?php echo Html::hidden('require_pending_reason', ['value' => $require_pending ? 1 : 0]); ?>
                  <?php echo Html::hidden('require_solution_model', ['value' => $require_model ? 1 : 0]); ?>
                  <?php echo Html::hidden('require_solution_type', ['value' => $require_type ? 1 : 0]); ?>

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

                  <button type="submit" class="btn btn-primary">
                     <i class="ti ti-device-floppy me-1"></i>
                     <?php echo __('Save', 'kanban'); ?>
                  </button>
               </form>
            </div>
         </div>

         <div class="card mt-3">
            <div class="card-header">
               <i class="ti ti-asterisk me-1"></i>
               <?php echo __('Required fields on column changes', 'kanban'); ?>
            </div>
            <div class="card-body">
               <form method="post" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                  <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>
                  <input type="hidden" name="kanban_config" value="1">
                  <?php echo Html::hidden('refresh_options', ['value' => $refresh_str]); ?>
                  <?php echo Html::hidden('status_order', ['value' => $order_str]); ?>
                  <?php echo Html::hidden('enable_drag_drop', ['value' => $drag_enabled ? 1 : 0]); ?>

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

                  <button type="submit" class="btn btn-primary">
                     <i class="ti ti-device-floppy me-1"></i>
                     <?php echo __('Save', 'kanban'); ?>
                  </button>
               </form>
            </div>
         </div>
      </div>
      <?php
      return true;
   }
}
