<?php

if (!function_exists('renderIconAssets')) {
    function renderIconAssets(string $localHref): void
    {
        $cdnHref = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
        $fallbackHref = htmlspecialchars($localHref, ENT_QUOTES, 'UTF-8');

        echo '<link rel="stylesheet" href="' . $cdnHref . '" onerror="this.onerror=null;this.href=\'' . $fallbackHref . '\';">';
    }
}
