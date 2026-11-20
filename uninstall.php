<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
// Retain plugin tables, settings, encrypted evidence and its key on uninstall.
// Deactivation cancels scheduled email work; reinstalling can reuse retained data.
// Managers explicitly remove proof through the protected transaction workflow.
// Minimal payment receipts remain for duplicate-payment protection.
