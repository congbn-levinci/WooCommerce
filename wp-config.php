<?php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true); // Specified log path
define('WP_DEBUG_DISPLAY', false);
define('FS_METHOD', 'direct');

// Added for security and optimization
define('DISALLOW_FILE_EDIT', true); // Disable theme/plugin editor
define('WP_MEMORY_LIMIT', '512M'); // Match uploads.ini memory_limit
define('WP_MAX_MEMORY_LIMIT', '512M'); // Ensure admin memory limit
?>