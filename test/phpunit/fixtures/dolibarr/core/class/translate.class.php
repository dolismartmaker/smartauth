<?php

/**
 * Minimal Translate class for integration tests
 */
class Translate
{
    public $dir;
    public $defaultlang;
    public $charset_output = 'UTF-8';

    private $tab_translate = [];

    public function __construct($dir, $conf)
    {
        $this->dir = $dir;
    }

    public function setDefaultLang($srclang = 'en_US')
    {
        $this->defaultlang = $srclang;
    }

    public function load($domain, $alt = 0, $stopafterdirection = 0, $forcelangdir = '', $loadfromfileonly = 0, $forcelang = '')
    {
        // No-op in tests - we don't have real translation files
        return 1;
    }

    public function loadLangs($domains)
    {
        if (!is_array($domains)) {
            $domains = [$domains];
        }
        foreach ($domains as $domain) {
            $this->load($domain);
        }
    }

    public function trans($key, $param1 = '', $param2 = '', $param3 = '', $param4 = '', $maxsize = 0)
    {
        // Return key as-is if no translation exists
        if (isset($this->tab_translate[$key])) {
            $str = $this->tab_translate[$key];
        } else {
            $str = $key;
        }

        // Simple parameter replacement
        if ($param1 !== '') {
            $str = str_replace('%s', $param1, $str);
        }

        return $str;
    }

    public function transnoentities($key, $param1 = '', $param2 = '', $param3 = '', $param4 = '', $param5 = '')
    {
        return $this->trans($key, $param1, $param2, $param3, $param4);
    }

    public function transnoentitiesnoconv($key, $param1 = '', $param2 = '', $param3 = '', $param4 = '', $param5 = '')
    {
        return $this->trans($key, $param1, $param2, $param3, $param4);
    }

    public function getLabelFromKey($db, $key, $tablename, $fieldkey, $fieldlabel, $keyforselect = '', $filteronentity = 0)
    {
        return $key;
    }

    public function getCurrencyAmount($currency_code, $amount)
    {
        return $amount . ' ' . $currency_code;
    }

    public function getCurrencySymbol($currency_code)
    {
        $symbols = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£'
        ];
        return $symbols[$currency_code] ?? $currency_code;
    }
}
