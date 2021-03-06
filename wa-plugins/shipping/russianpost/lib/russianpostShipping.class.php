<?php

/**
 *
 * @property region
 * @property halfkilocost
 * @property currency
 * @property overhalfkilocost
 * @property $caution
 * @property $max_weight
 * @property $complex_calculation_weight
 * @property $commission
 *
 * @property string $company_name
 * @property string $address1
 * @property string $address2
 * @property string $zip
 * @property string $inn
 * @property string $bank_kor_number
 * @property string $bank_name
 * @property string $bank_account_number
 * @property string $bik
 * @property string $color
 *
 */
class russianpostShipping extends waShipping
{
    protected function initControls()
    {
        $this
            ->registerControl('WeightCosts')
            ->registerControl('RegionRatesControl');
        parent::initControls();
    }

    public static function settingWeightCosts($name, $params = array())
    {
        foreach ($params as $field => $param) {
            if (strpos($field, 'wrapper')) {
                unset($params[$field]);
            }
        }
        $control = '';
        if (!isset($params['value']) || !is_array($params['value'])) {
            $params['value'] = array();
        }
        $costs = $params['value'];

        waHtmlControl::addNamespace($params, $name);
        $control .= '<table class="zebra">';
        $params['description_wrapper'] = '%s';
        $currency = waCurrency::getInfo('RUB');
        $params['title_wrapper'] = '%s';
        $params['control_wrapper'] = '<tr title="%3$s"><td>%1$s</td><td>&rarr;</td><td>%2$s '.$currency['sign'].'</td></tr>';
        $params['size'] = 6;
        for ($zone = 1; $zone <= 5; $zone++) {
            $params['value'] = floatval(isset($costs[$zone]) ? $costs[$zone] : 0.0);
            $params['title'] = "Пояс {$zone}";
            $control .= waHtmlControl::getControl(waHtmlControl::INPUT, $zone, $params);
        }
        $control .= "</table>";

        return $control;
    }

    public static function settingRegionRatesControl($name, $params = array())
    {
        foreach ($params as $field => $param) {
            if (strpos($field, 'wrapper')) {
                unset($params[$field]);
            }
        }

        if (empty($params['value']) || !is_array($params['value'])) {
            $params['value'] = array();
        }
        $control = '';

        waHtmlControl::addNamespace($params, $name);

        $cm = new waCountryModel();

        $countries = $cm->all();

        $rm = new waRegionModel();
        if ($regions = $rm->getByCountry('rus')) {

            $control .= "<table class=\"zebra\"><thead>";
            $control .= "<tr class=\"gridsheader\"><th colspan=\"3\">";
            $control .= htmlentities('Распределите регионы по тарифным поясам Почты России', ENT_QUOTES, 'utf-8');
            $control .= "</th>";
            $control .= "<th>Только авиа</th>";
            $control .= "</th></tr></thead><tbody>";

            $params['control_wrapper'] = '<tr><td>%s</td><td>&rarr;</td><td>%s</td><td>%s</td></tr>';
            $params['title_wrapper'] = '%s';
            $params['description_wrapper'] = '%s';
            $params['options'] = array();
            $params['options'][0] = _wp('*** пояс не выбран ***');
            for ($region = 1; $region <= 5; $region++) {
                $params['options'][$region] = sprintf(_wp('Пояс %d'), $region);
            }
            $avia_params = $params;
            $avia_params['control_wrapper'] = '%2$s';
            $avia_params['description_wrapper'] = false;
            $avia_params['title_wrapper'] = false;

            foreach ($regions as $region) {
                $name = 'zone';
                $id = $region['code'];
                if (empty($params['value'][$id])) {
                    $params['value'][$id] = array();
                }
                $params['value'][$id] = array_merge(array($name => 0, 'avia_only' => false), $params['value'][$id]);
                $region_params = $params;

                waHtmlControl::addNamespace($region_params, $id);
                $avia_params = array(
                    'namespace'           => $region_params['namespace'],
                    'control_wrapper'     => '%2$s',
                    'description_wrapper' => false,
                    'title_wrapper'       => false,
                    'value'               => $params['value'][$id]['avia_only'],
                );
                $region_params['value'] = max(0, min(5, $params['value'][$id][$name]));

                $region_params['description'] = waHtmlControl::getControl(waHtmlControl::CHECKBOX, 'avia_only', $avia_params);
                $region_params['title'] = $region['name'];
                if ($region['code']) {
                    $region_params['title'] .= " ({$region['code']})";
                }
                $control .= waHtmlControl::getControl(waHtmlControl::SELECT, 'zone', $region_params);
            }
            $control .= "</tbody>";
            $control .= "</table>";
        } else {
            $control .= 'Не определено ни одной области. Для работы модуля необходимо определить хотя бы одну область в России (см. раздел «Страны и области»).';
        }
        return $control;
    }

    public function allowedAddress()
    {
        $address = array(
            'country' => 'rus',
            'region'  => array(),
        );
        foreach ($this->region as $region => $options) {
            if (!empty($options['zone'])) {
                $address['region'][] = $region;
            }
        }
        return array($address);
    }

    public function requestedAddressFields()
    {
        return array(
            'zip'     => array(),
            'country' => array(),
            'region'  => array(),
            'city'    => array(),
            'street'  => array(),
        );
    }

    private function getZoneRates($weight, $price, $zone)
    {
        $zone = max(1, min(5, $zone));
        $rate = array();
        $halfkilocost = $this->halfkilocost;
        $overhalfkilocost = $this->overhalfkilocost;

        $rate['ground'] = $this->halfkilocost[$zone] + $this->overhalfkilocost[$zone] * ceil((($weight < 0.5 ? 0.5 : $weight) - 0.5) / 0.5);

        $rate['air'] = $rate['ground'] + $this->getSettings('air');

        if ($this->getSettings('caution') || ($weight > $this->complex_calculation_weight)) {

            $rate['ground'] *= 1.3;
            $rate['air'] *= 1.3;
        }

        $rate['ground'] += $price * ($this->commission / 100);
        $rate['air'] += $price * ($this->commission / 100);
        return $rate;
    }

    public function calculate()
    {

        $services = array();
        $region_id = $this->getAddress('region');

        $zone = null;
        $delivery_date = waDateTime::format('humandate', strtotime('+1 week')).' — '.waDateTime::format('humandate', strtotime('+2 week'));
        $weight = $this->getTotalWeight();
        if ($weight > $this->max_weight) {
            $services = sprintf("Вес отправления (%0.2f) превышает максимально допустимый (%0.2f)", $weight, $this->max_weight);
        } else {
            if ($region_id) {
                if (!empty($this->region[$region_id]) && !empty($this->region[$region_id]['zone'])) {

                    $rate = $this->getZoneRates($weight, $this->getTotalPrice(), $this->region[$region_id]['zone']);
                    if (empty($this->region[$region_id]['avia_only'])) {
                        $services['ground'] = array(
                            'name'         => 'Наземный транспорт',
                            'id'           => 'ground',
                            'est_delivery' => $delivery_date,
                            'rate'         => $rate['ground'],
                            'currency'     => 'RUB',
                        );
                    }
                    $services['avia'] = array(
                        'name'         => 'Авиа',
                        'id'           => 'avia',
                        'est_delivery' => $delivery_date,
                        'rate'         => $rate['air'],
                        'currency'     => 'RUB',
                    );
                } else {
                    $services = false;
                }

            } else {
                $price = $this->getTotalPrice();
                $rate_min = $this->getZoneRates($weight, $price, 1);
                $rate_max = $this->getZoneRates($weight, $price, 5);
                $services['ground'] = array(
                    'name'         => 'Наземный транспорт',
                    'id'           => 'ground',
                    'est_delivery' => $delivery_date,
                    'rate'         => array($rate_min['ground'], $rate_max['ground']),
                    'currency'     => 'RUB',
                );
                $services['avia'] = array(
                    'name'         => 'Авиа',
                    'id'           => 'avia',
                    'est_delivery' => $delivery_date,
                    'rate'         => array($rate_min['air'], $rate_max['air']),
                    'currency'     => 'RUB',
                );
            }
        }
        return $services;
    }

    public function getPrintForms()
    {
        return extension_loaded('gd') ? array(
            113 => array(
                'name'        => 'Форма №113',
                'description' => 'Бланк почтового перевода наложенного платежа',
            ),
            116 => array(
                'name'        => 'Форма №116',
                'description' => 'Бланк сопроводительного адреса к посылке',
            ),
        ) : array();
    }

    public function displayPrintForm($id, waOrder $order, $params = array())
    {
        $method = 'displayPrintForm'.$id;
        if (method_exists($this, $method)) {
            if (extension_loaded('gd')) {
                return $this->$method($order, $params);
            } else {
                throw new waException('PHP extension GD not loaded');
            }
        } else {
            throw new waException('Print form not found');
        }

    }

    private function displayPrintForm113(waOrder $order, $params = array())
    {
        $strict = true;
        $request = waRequest::request();

        $order['rub'] = intval(waRequest::request('rub', round(floor($order->total))));
        $order['cop'] = min(99, max(0, intval(waRequest::request('cop', round($order->total * 100 - $order['rub'] * 100)))));

        switch ($side = waRequest::get('side', ($order ? '' : 'print'), waRequest::TYPE_STRING)) {
            case 'front':
                $image_info = null;
                if ($image = $this->read('f113en_front.gif', $image_info)) {
                    if ($this->color) {
                        if ($image_stripe = $this->read('f113en_stripe.gif', $image_info)) {
                            imagecopy($image, $image_stripe, 808, 663, 0, 0, $image_info[0], $image_info[1]);
                        }
                    }

                    $format = '%.W{n0} %.2{f0}';
                    $this->printOnImage($image, sprintf('%d', $order['rub']), 1730, 670);
                    $this->printOnImage($image, sprintf('%02d', $order['cop']), 1995, 670);
                    $this->printOnImage($image, waRequest::request('order_amount', waCurrency::format($format, $order->total, $order->currency)), 856, 735, 30);
                    $this->printOnImage($image, $this->company_name, 915, 800);
                    $this->printOnImage($image, $this->address1, 915, 910);
                    $this->printOnImage($image, $this->address2, 824, 975);
                    $this->printOnImagePersign($image, $this->zip, 1985, 1065, 34, 35);
                    $this->printOnImagePersign($image, $this->inn, 920, 1135, 34, 35);
                    $this->printOnImagePersign($image, $this->bank_kor_number, 1510, 1135, 34, 35);
                    $this->printOnImage($image, $this->bank_name, 1160, 1194);
                    $this->printOnImagePersign($image, $this->bank_account_number, 1018, 1250, 34, 35);
                    $this->printOnImagePersign($image, $this->bik, 1885, 1250, 34, 35);

                    header("Content-type: image/gif");
                    imagegif($image);
                    exit;
                }
                break;
            case 'back':
                $image_info = null;
                if ($image = $this->read('f113en_back.gif', $image_info)) {
                    header("Content-type: image/gif");
                    imagegif($image);
                    exit;
                }
                break;
            case 'print':
                if (!$strict && !$order) {
                    $this->view()->assign('action', 'preview');
                }
                $this->view()->assign('editable', waRequest::post() ? false : true);
                break;
            default:
                $this->view()->assign(array(
                    'src_front' => http_build_query(array_merge($request, array('side' => 'front'))),
                    'src_back'  => http_build_query(array_merge($request, array('side' => 'back'))),
                ));
                if (!$strict && !$order) {
                    $this->view()->assign('action', 'preview');
                }
                $this->view()->assign('order', $order);
                $this->view()->assign('editable', waRequest::post() ? false : true);
                break;
        }

        return $this->view()->fetch($this->path.'/templates/form113.html');
    }

    private function view()
    {
        static $view;
        if (!$view) {
            $view = wa()->getView();
        }
        return $view;
    }

    private function splitAddress(waOrder $order)
    {
        $address_chunks = array(
            $order->shipping_address['street'],
            $order->shipping_address['city'],
            $order->shipping_address['region_name'],
            ($order->shipping_address['country'] != 'rus') ? $order->shipping_address['country_name'] : '',
        );
        $address_chunks = array_filter($address_chunks, 'strlen');
        $address = array(implode(', ', $address_chunks), '');
        if (preg_match('/^(.{25,40})[,\s]+(.+)$/u', $address[0], $matches)) {

            array_shift($matches);
            $matches[0] = rtrim($matches[0], ', ');
            $address = $matches;
        }
        return $address;
    }

    private function displayPrintForm116(waOrder $order, $params = array())
    {
        $strict = true;
        $request = waRequest::request();
        switch ($side = waRequest::get('side', ($order ? '' : 'print'), waRequest::TYPE_STRING)) {
            case 'front':
                $image_info = null;
                if ($image = $this->read('f116_front.gif', $image_info)) {
                    $address = $this->splitAddress($order);
                    $this->printOnImage($image, waRequest::request('order_amount', $order->total), 294, 845, 24);
                    $this->printOnImage($image, waRequest::request('order_price', $order->total), 294, 747, 24);
                    //customer
                    $this->printOnImage($image, waRequest::request('shipping_name', $order->contact_name), 390, 915);
                    $this->printOnImage($image, waRequest::request('shipping_address_1', $address[0]), 390, 975);
                    $this->printOnImage($image, waRequest::request('shipping_address_2', $address[1]), 300, 1040);
                    $this->printOnImagePersign($image, waRequest::request('shipping_zip', $order->shipping_address['zip']), 860, 1105, 55, 35);

                    //company
                    $this->printOnImage($image, $this->company_name, 420, 1170);
                    $this->printOnImage($image, $this->address1, 400, 1237);
                    $this->printOnImage($image, $this->address2, 300, 1304);
                    $this->printOnImagePersign($image, $this->zip, 1230, 1304, 55, 35);

                    //additional
                    $this->printOnImage($image, waRequest::request('order_price_d', waCurrency::format('%2', $order->total, $order->currency)), 590, 2003);
                    $this->printOnImage($image, waRequest::request('order_amount_d', waCurrency::format('%2', $order->total, $order->currency)), 1280, 2003);

                    $this->printOnImage($image, waRequest::request('shipping_name', $order->contact_name), 390, 2085);

                    $this->printOnImage($image, waRequest::request('shipping_address_1', $address[0]), 390, 2170);
                    $this->printOnImage($image, waRequest::request('shipping_address_2', $address[1]), 300, 2260);

                    $this->printOnImagePersign($image, waRequest::request('shipping_zip', $order->shipping_address['zip']), 1230, 2260, 55, 35);

                    header("Content-type: image/gif");
                    imagegif($image);
                    exit;
                }
                break;
            case 'back':
                $image_info = null;

                if ($image = $this->read('f116_back.gif', $image_info)) {
                    header("Content-type: image/gif");
                    imagegif($image);
                    exit;
                }
                break;
            case 'print':
                if (!$strict && !$order) {
                    $this->view()->assign('action', 'preview');
                }
                $this->view()->assign('editable', false);
                $this->view()->assign('order', $order);
                break;
            default:
                $this->view()->assign(array(
                    'src_front' => http_build_query(array_merge($request, array('side' => 'front'))),
                    'src_back'  => http_build_query(array_merge($request, array('side' => 'back'))),
                ));
                if (!$strict && !$order) {
                    $this->view()->assign('action', 'preview');
                }
                $this->view()->assign('editable', waRequest::post() ? false : true);
                $this->view()->assign('order', $order);
                $this->view()->assign('address', $this->splitAddress($order));
                break;
        }
        return $this->view()->fetch($this->path.'/templates/form116.html');
    }

    public function tracking($tracking_id = null)
    {
        return 'Отслеживание отправления: <a href="http://emspost.ru/ru/tracking/?id='.$tracking_id.'" target="_blank">http://emspost.ru/ru/tracking/?id='.$tracking_id.'</a>';
    }

    public function allowedCurrency()
    {
        return 'RUB';
    }

    public function allowedWeightUnit()
    {
        return 'kg';
    }

    public function saveSettings($settings = array())
    {
        $fields = array(
            'halfkilocost',
            'overhalfkilocost',
        );
        foreach ($fields as $field) {
            if (ifempty($settings[$field])) {
                foreach ($settings[$field] as & $value) {
                    if (strpos($value, ',') !== false) {
                        $value = str_replace(',', '.', $value);
                    }
                    $value = str_replace(',', '.', (double) $value);
                }
                unset($value);
            }
        }
        return parent::saveSettings($settings);
    }

    private function printOnImage(&$image, $text, $x, $y, $font_size = 35)
    {
        $y += $font_size;
        static $font_path = null;
        static $text_color = null;
        static $mode;
        static $convert = false;

        if (is_null($font_path)) {
            $font_path = $this->path.'/lib/config/data/arial.ttf';
            $font_path = (file_exists($font_path) && function_exists('imagettftext')) ? $font_path : false;
        }
        if (is_null($text_color)) {
            $text_color = ($this->COLOR && false) ? ImageColorAllocate($image, 32, 32, 96) : ImageColorAllocate($image, 16, 16, 16);
        }

        if (empty($mode)) {
            if ($font_path) {
                $info = gd_info();
                if (!empty($info['JIS-mapped Japanese Font Support'])) {
                    //any2eucjp
                    $convert = true;
                }
                if (!empty($info['FreeType Support']) && version_compare(preg_replace('/[^0-9\.]/', '', $info['GD Version']), '2.0.1', '>=')) {
                    $mode = 'ftt';
                } else {
                    $mode = 'ttf';
                }
            } else {
                $mode = 'string';
            }
        }
        if ($convert) {
            $text = iconv('utf-8', 'EUC-JP', $text);
        }

        switch ($mode) {
            case 'ftt':
                imagefttext($image, $font_size, 0, $x, $y, $text_color, $font_path, $text);
                break;
            case 'ttf':
                imagettftext($image, $font_size, 0, $x, $y, $text_color, $font_path, $text);
                break;
            case 'string':
                imagestring($image, $font_size, $x, $y, $text, $text_color);
                break;
        }
    }
    private function printOnImagePersign(&$image, $text, $x, $y, $cell_size = 34, $font_size = 35)
    {
        $size = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $size; $i++) {
            $this->printOnImage($image, mb_substr($text, $i, 1, 'UTF-8'), $x, $y, $font_size);
            $x += $cell_size;
        }
    }
    private function read($file, &$info)
    {
        if ($file) {
            $file = $this->path.'/lib/config/data/'.$file;
        }
        $info = @getimagesize($file);
        if (!$info)
            return false;
        switch ($info[2]) {
            case 1:
                // Create recource from gif image
                $srcIm = @imagecreatefromgif($file);
                break;
            case 2:
                // Create recource from jpg image
                $srcIm = @imagecreatefromjpeg($file);
                break;
            case 3:
                // Create resource from png image
                $srcIm = @imagecreatefrompng($file);
                break;
            case 5:
                // Create resource from psd image
                break;
            case 6:
                // Create recource from bmp image imagecreatefromwbmp
                $srcIm = @imagecreatefromwbmp($file);
                break;
            case 7:
                // Create resource from tiff image
                break;
            case 8:
                // Create resource from tiff image
                break;
            case 9:
                // Create resource from jpc image
                break;
            case 10:
                // Create resource from jp2 image
                break;
            default:
                break;
        }
        return !$srcIm ? false : $srcIm;
    }
}
