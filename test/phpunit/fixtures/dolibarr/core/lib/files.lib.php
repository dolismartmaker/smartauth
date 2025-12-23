<?php

/**
 * Minimal files.lib.php stubs for integration tests
 */

if (!function_exists('dol_delete_dir_recursive')) {
    function dol_delete_dir_recursive($dir, $count = 0, $nophperrors = 0, $onlysub = 0, $countdeleted = null)
    {
        return 1;
    }
}

if (!function_exists('dol_delete_file')) {
    function dol_delete_file($file, $disableglob = 0, $nophperrors = 0, $nohook = 0, $object = null, $allowdotdot = false, $indexdatabase = 1)
    {
        return 1;
    }
}

if (!function_exists('dol_mkdir')) {
    function dol_mkdir($dir, $dataroot = '', $newmask = null)
    {
        return 1;
    }
}

if (!function_exists('dol_copy')) {
    function dol_copy($srcfile, $destfile, $newmask = 0, $overwriteifexists = 1)
    {
        return 1;
    }
}

if (!function_exists('dol_move')) {
    function dol_move($srcfile, $destfile, $newmask = 0, $overwriteifexists = 1, $testvirus = 0, $indexdatabase = 1)
    {
        return 1;
    }
}

if (!function_exists('dol_is_file')) {
    function dol_is_file($pathoffile)
    {
        return false;
    }
}

if (!function_exists('dol_is_dir')) {
    function dol_is_dir($folder)
    {
        return false;
    }
}

if (!function_exists('dol_dir_list')) {
    function dol_dir_list($path, $types = 'files', $recursive = 0, $filter = '', $excludefilter = null, $sortcriteria = 'name', $sortorder = SORT_ASC, $mode = 0, $nohook = 0, $relativename = '', $donotfollowsymlinks = 0)
    {
        return [];
    }
}

if (!function_exists('dolReplaceInFile')) {
    function dolReplaceInFile($srcfile, $arrayreplacement, $destfile = '', $newmask = 0, $indexdatabase = 0, $arrayreplacementisregex = 0)
    {
        return 1;
    }
}

if (!function_exists('dol_filemtime')) {
    function dol_filemtime($pathoffile)
    {
        return time();
    }
}

if (!function_exists('dol_filesize')) {
    function dol_filesize($pathoffile)
    {
        return 0;
    }
}

if (!function_exists('dol_mimetype')) {
    function dol_mimetype($file, $default = 'application/octet-stream', $mode = 0)
    {
        return $default;
    }
}
