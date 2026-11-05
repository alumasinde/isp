<?php
/**
 * Universal Storage Symlink Generator for cPanel / VPS / Addon Domains / Subdomains
 *
 * Usage:
 * 1. Upload this file directly into the public web root folder of your domain
 *    (e.g., public_html, subdomain.domain.com, or your custom public folder).
 * 2. Visit https://your-domain.com/cpanel-storage-link.php in your web browser.
 * 3. Once it shows "Success", DELETE this file from the server immediately!
 */

// 1. Current web root is wherever this file was uploaded
$webroot = __DIR__;
$shortcut = $webroot . '/storage';

// 2. Auto-detect project storage path
$home = dirname($webroot);
$candidates = [
    $home . '/master_core/storage/app/public',
    dirname(__DIR__) . '/storage/app/public',
    $home . '/storage/app/public',
];

$target = null;
if (!empty($_GET['target'])) {
    $target = rtrim($_GET['target'], '/\\');
} else {
    foreach ($candidates as $cand) {
        if (is_dir($cand)) {
            $target = $cand;
            break;
        }
    }
    // Fallback default
    if (!$target) {
        $target = $home . '/master_core/storage/app/public';
    }
}

echo "<h2>Universal Storage Symlink Generator</h2>";
echo "<b>Webroot Folder (Shortcut):</b> " . htmlspecialchars($shortcut) . "<br>";
echo "<b>Source Folder (Target):</b> " . htmlspecialchars($target) . "<br><br>";

// Remove existing symlink if broken or present
if (is_link($shortcut)) {
    unlink($shortcut);
    echo "Removed existing symlink.<br>";
}

// Create symlink
if (@symlink($target, $shortcut)) {
    echo "<h3 style='color: green;'>✅ Success! Storage link created at: " . htmlspecialchars($shortcut) . "</h3>";
    echo "<p style='color: red;'><b>SECURITY ACTION REQUIRED:</b> Please delete this file now!</p>";
} else {
    echo "<h3 style='color: red;'>❌ Failed to create symlink.</h3>";
    echo "<p>Troubleshooting tips:</p>";
    echo "<ul>";
    echo "<li>Make sure <code>" . htmlspecialchars($target) . "</code> exists. (You can specify a custom target using <code>?target=/full/path/to/storage/app/public</code>).</li>";
    echo "<li>If a normal directory named <code>storage</code> already exists in this folder, rename or delete it first.</li>";
    echo "<li>Ensure PHP <code>symlink</code> is enabled in your PHP Settings (disable_functions).</li>";
    echo "</ul>";
}
