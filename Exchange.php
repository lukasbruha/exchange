<?php

namespace h4kuna;

use Nette,
    Nette\Http\SessionSection,
    Nette\Http\Request;

/**
 * PHP > 5.3
 *
 * @author Milan Matějček
 * @since 2009-06-22 - version 0.5
 * @version 3.1
 * @property-read $default
 * @property $date
 * @property $vat
 */
class Exchange extends \ArrayIterator implements IExchange {

    /**
     * number of version
     * @var string
     */
    private static $version = FALSE;

    /** @var Nette\Utils\Html */
    private static $href;

    /**
     * param in url for change value
     */

    const PARAM_CURRENCY = 'currency';
    const PARAM_VAT = 'vat';

//-----------------config section-----------------------------------------------
    /**
     * default money on web, must be UPPER
     * @var string
     */
    protected $default;

    /**
     * show actual money for web and is first in array, must be UPPER
     * @var string
     */
    protected $web;

    /** @var bool */
    protected $globalVat = FALSE;

    /**
     * vat, only prefered value [%]
     * @example 20% = 1.20
     * @var real
     */
    protected $vat = 1.2;
    private $percentVat;

//------------------------------------------------------------------------------

    /**
     * last working value
     * @var array
     */
    protected $lastChange = array(NULL, NULL);

    /** @var DateTime */
    private $date;

    /** @var Storage */
    private $storage;

    /** @var Download */
    private $download;

    /** @var NumberFormat */
    private $number;

    /** @var SessionSection */
    private $session;

    /** @var Request */
    private $request;

    public function __construct(Storage $storage, Request $request, SessionSection $session, NumberFormat $number = NULL, Download $download = NULL) {
        parent::__construct();
        $this->storage = $storage;
        $this->download = $download ? $download : new CnbDay;
        $this->number = $number ? $number : new NumberFormat;
        $this->session = $session;
        $this->request = $request;
    }

    /**
     * return property of currency
     * @example usdProfil
     * @param string $name
     * @param strong $args
     * @return mixed
     */
    public function __get($name) {
        $code = strtoupper(substr($name, 0, 3));
        if (!$this->offsetExists($code)) {
            $code = $this->web;
        } else {
            $name = strtolower(substr($name, 3));
        }

        return ($name) ? $this[$code][$name] : $this[$code];
    }

    /** set date for download */
    public function setDate(\DateTime $date = NULL) {
//        $date = ($date instanceof \DateTime || !$date) ? $date : new \DateTime($date);
//        if ($date == $this->date) {
//            return;
//        }
//        $this->date = $date;
//        $this->download($this->date);
//        $store = $this->getStorage();
//        foreach ($this as $k => $v) {
//            $data = (array) $store[$k];
//            $data['profil'] = $v['profil'];
//            $this->offsetSet($k, $data);
//        }
    }

    /**
     * internal use
     * @param number $vat
     */
    protected function setPercentVat($vat) {
        $this->percentVat = $vat;
    }

    /**
     * 1.2 or 20
     * @param number $vat
     */
    public function setVat($vat) {
        if ($vat > 2) {
            $this->setPercentVat($vat);
            $vat /= 100;
            $vat += 1;
        } else {
            $this->setPercentVat(($vat - 1) * 100);
        }
        $this->vat = $vat;
    }

//-----------------methods for template
    /**
     * @param string $code
     * @param bool $symbol
     * @return Nette\Utils\Html
     */
    public function currencyLink($code, $symbol = TRUE) {
        $code = $this->loadCurrency($code);
        $a = self::getHref();
        $a->setText(($symbol) ? $this[$code]['profil']->symbol : $code);

        if ($this->web === $code) {
            $a->class = 'current';
        }
        return $a->href(NULL, array(self::PARAM_CURRENCY => $code));
    }

    /**
     * create link for vat
     * @param string $textOn
     * @param string $textOff
     * @return Nette\Utils\Html
     */
    public function vatLink($textOn, $textOff) {
        $a = self::getHref();
        $a->href(NULL, array(self::PARAM_VAT => !$this->globalVat));
        if ($this->globalVat) {
            $a->setText($textOff);
        } else {
            $a->setText($textOn);
        }
        return $a;
    }

    /**
     * array for form to addSelect
     * @param string $key
     * @return array
     */
    public function selectInput($key = self::SYMBOL) {
        $out = array();
        foreach ($this as $k => $v) {
            $out[$k] = isset($v[$key]) ? $v[$key] : $k;
        }
        return $out;
    }

    /**
     * create helper to template
     */
    public function registerAsHelper(Nette\Templating\Template $tpl) {
        $tpl->registerHelper('formatVat', callback($this, 'formatVat'));
        $tpl->registerHelper('currency', callback($this, 'format'));
        $tpl->exchange = $this;
    }

    /**
     * transfer number by exchange rate
     * @param double|int|string $price number
     * @param string $from default currency
     * @param string $to output currency
     * @param int $round number round
     * @return double
     */
    public function change($price, &$from = NULL, &$to = NULL, $round = FALSE) {
        if (is_string($price)) {
            $price = (double) Math::stroke2point($price);
        }

        $from = (!$from) ? $this->default : $this->loadCurrency($from);
        $to = (!$to) ? $this->web : $this->loadCurrency($to);
        $price = $this[$to][self::RATE] / $this[$from][self::RATE] * $price;

        if ($round !== FALSE) {
            $price = round($price, $round);
        }

        return $price;
    }

    /**
     * count, format price and set vat
     * @param number $number price
     * @param string|bool $from TRUE currency doesn't counting, FALSE set actual
     * @param string $to output currency, FALSE set actual
     * @param bool|real $vat use vat, but get vat by method $this->formatVat(), look at to globatVat upper
     * @return number string
     */
    public function format($number, $from = NULL, $to = NULL, $vat = FALSE) {
        $old = $this->web;
        if ($to != FALSE) {
            $to = $this->loadCurrency($to);
            $this->web = $to;
        }

        if ($from !== TRUE) {
            $number = $this->change($number, $from, $to);
        }

        $getVat = FALSE;
        if ($vat === FALSE) {
            $vat = $this->vat;
        } elseif ($vat === TRUE) {
            $getVat = TRUE;
            $vat = $this->vat;
        } else {
            $vat = (double) $vat;
        }

        $this->lastChange[0] = $number * $vat;
        $this->lastChange[1] = $this[$to]['profil'];

        if ($this->globalVat || $getVat) {
            $number = $this->lastChange[0];
        }

        $out = $this[$to]['profil'];
        $out->number = $number;

        if ($to != FALSE) {
            $this->web = $old;
        } else {
            $to = $this->web;
        }

        return $out;
    }

    /**
     * before call this method MUST call method format()
     * formating price only with vat
     * @return string
     */
    public function formatVat() {
        return $this->lastChange[1]->number = $this->lastChange[0];
    }

    /**
     * load currency by code
     * @param string $code
     * @return string
     */
    public function loadCurrency($code, $property = array()) {
        $code = strtoupper($code);
        if (!$this->default) {
            $this->default = $code;
            $this->init();
        }

        if (!$this->offsetExists($code)) {
            $this->offsetSet($code, $this->storage[$code]);
        }

        if ($property || !isset($this[$code]['profil'])) {
            if (!$property) {
                $profil = $this->getDefaultProfile();
                $profil->setSymbol($code);
            } elseif (is_array($property)) {
                $profil = $this->getDefaultProfile();
                foreach ($property as $k => $v) {
                    $profil->{$k} = $v;
                }
            } elseif ($property instanceof NumberFormat) {
                $profil = $property;
            }

            $this[$code]['profil'] = $profil;
        }
        return $code;
    }

// <editor-fold defaultstate="collapsed" desc="getter">
    /**
     * @return Exchange
     */
    public function loadAll() {
        $code = $this->default;
        do {
            $this->loadCurrency($code);
            $code = $this[$code]['next'];
        } while ($code);
        return $this;
    }

    /** @return float */
    public function getVat() {
        return $this->vat;
    }

    /** @return number */
    public function getPercentVat() {
        return $this->percentVat;
    }

    /** @return string */
    public function getWeb() {
        return $this->web;
    }

    public function getDefault() {
        return $this->default;
    }

    public function getDate() {
        if (!$this->date) {
            $this->setDate();
        }
        return $this->date;
    }

    /**
     * version of this class
     * @return string
     */
    static public function getVersion() {
        if (self::$version === FALSE) {
            $rc = new \ReflectionClass(__CLASS__);
            preg_match('~@version (.*)~', $rc->getDocComment(), $array);
            self::$version = $array[1];
        }
        return self::$version;
    }

// </editor-fold>
//-----------------protected
    protected function init() {
        $this->download->setDefault($this->default);
        $this->download();
        $this->session();
        if (!$this->percentVat) {
            $this->setVat($this->vat);
        }
    }

    /**
     * start download the source
     * @return void
     */
    protected function download() {
        if (!isset($this->storage[$this->default])) {
            $this->storage->import($this->download->downloading(), $this->default);
        }
    }

    /**
     * setup session
     */
    protected function session() {
//-------------crrency
        $qVal = strtoupper($this->request->getQuery(self::PARAM_CURRENCY));
        if (!isset($this->storage[$qVal])) {
            $qVal = NULL;
        }

        if ($qVal) {
            $this->session->currency = $qVal;
        } elseif (!isset($this->session->currency)) {
            $this->session->currency = is_null($this->web) ? $this->default : $this->web;
        }
        $this->web = $this->session->currency;

//-------------vat
        $qVal = $this->request->getQuery(self::PARAM_VAT);
        if ($qVal !== NULL) {
            $this->globalVat = $this->session->vat = (bool) $qVal;
        } elseif (!isset($this->session->vat)) {
            $this->session->vat = $this->globalVat;
        } else {
            $this->globalVat = $this->session->vat;
        }
    }

    /** @return Nette\Utils\Html */
    protected static function getHref() {
        if (!self::$href)
            self::$href = Nette\Utils\Html::el('a');
        return clone self::$href;
    }

    protected function getDefaultProfile() {
        return clone $this->number;
    }

}
