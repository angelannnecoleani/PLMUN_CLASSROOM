<?php
/**
 * Returns the correct URL + target behavior for viewing a material attachment
 * instead of downloading it, based on file type.
 *
 * Usage:
 *   $view = get_viewer_link($material['file']); // e.g. "uploads/123_notes.pdf"
 *   echo '<a href="'.$view['url'].'" target="'.$view['target'].'">';
 */
function get_viewer_link($relativePath){
    $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

    // Types browsers can render natively — just open in a new tab
    $nativeViewable = ['pdf','jpg','jpeg','png','gif','webp','mp4','mov','webm','mp3','wav'];

    if(in_array($ext, $nativeViewable)){
        return [
            'url'    => $relativePath,
            'target' => '_blank'
        ];
    }

    // Office file types — browsers always force-download these on their own.
    // Google Docs Viewer can display them instead, but ONLY if the file is
    // reachable on the public internet (not localhost/XAMPP).
    $officeTypes = ['doc','docx','ppt','pptx','xls','xlsx'];

    if(in_array($ext, $officeTypes)){
        // Build the absolute URL to the file so Google's viewer can fetch it
        $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host       = $_SERVER['HTTP_HOST'];
        $absoluteUrl = $protocol . '://' . $host . '/' . ltrim($relativePath, '/');

        $viewerUrl = 'https://docs.google.com/viewer?url=' . urlencode($absoluteUrl) . '&embedded=true';

        return [
            'url'    => $viewerUrl,
            'target' => '_blank'
        ];
    }

    // Fallback — unknown type, just open normally
    return [
        'url'    => $relativePath,
        'target' => '_blank'
    ];
}