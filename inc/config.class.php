<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class PluginKanbanConfig extends CommonGLPI {

   public static $rightname = 'config';

   /**
    * Get the configuration file path.
    */
   private static function getConfigFile(): string {
      return GLPI_CONFIG_DIR . '/kanban_config.php';
   }

   /**
    * Load config from file. Returns default if file missing.
    */
   public static function load(): array {
      $defaults = [
         'refresh_options' => [1, 2, 3],
      ];
      $file = self::getConfigFile();
      if (file_exists($file)) {
         $cfg = include $file;
         if (is_array($cfg)) {
            return array_merge($defaults, $cfg);
         }
      }
      return $defaults;
   }

   /**
    * Save config to file.
    */
   public static function save(array $data): bool {
      $file = self::getConfigFile();
      $content = "<?php\nreturn " . var_export($data, true) . ";\n";
      return file_put_contents($file, $content) !== false;
   }

   /**
    * Get available refresh options (in minutes).
    */
   public static function getRefreshOptions(): array {
      $cfg = self::load();
      return $cfg['refresh_options'] ?? [1, 2, 3];
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
         if (!Session::checkCSRF($_POST)) {
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

            $success = self::save([
               'refresh_options' => array_values($refresh_values),
            ]);
            if (!$success) {
               $error = __('Could not save configuration file', 'kanban');
            }
         }
      }

      $cfg = self::load();
      $refresh_str = implode(', ', $cfg['refresh_options'] ?? [1, 2, 3]);
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
      </div>
      <?php
      return true;
   }
}
