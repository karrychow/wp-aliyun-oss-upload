<?php
/*
 * Plugin Name: Aliyun OSS Upload
 * Version: 4.9.0
 * Description: Upload with Aliyun OSS, with modified OSS Wrapper and fully native image edit function support.
 * Plugin URI: https://github.com/karrychow/wp-aliyun-oss-upload
 * Author: Karry
 * Author URI: https://github.com/karrychow
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aliyun-oss-upload
 * Domain Path: /lang
 * Network: true
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action('init', 'oss_upload_init', 1);
function oss_upload_init(){
    if(!ouops('oss') || !ouops('oss_akey') || !ouops('oss_skey') || !ouops('oss_endpoint') || !ouops('oss_path')) return;
    define('OSS_ACCESS_ID', trim(ouops('oss_akey')));
    define('OSS_ACCESS_KEY', trim(ouops('oss_skey')));
    define('OSS_ENDPOINT', trim(ouops('oss_endpoint')));

    // Load Aliyun SDK and Adapter
    if (file_exists(dirname(__FILE__).'/aliyun-oss-php-sdk-2.7.2/autoload.php')) {
        require_once 'aliyun-oss-php-sdk-2.7.2/autoload.php';
    }
    require_once('lib/OU_ALIOSS_Adapter.php');
    require_once('lib/OSSWrapper.php');

    oss_upload_dir_loader();
    add_action('post_submitbox_misc_actions', 'oss_upload_post_action');
    add_action('add_meta_boxes', 'oss_upload_post_meta_boxes');
    add_filter('content_save_pre', 'oss_upload_post_save');
}

function ouops($k, $v=null){
    if(!$op = get_option('ouop')) $op = get_site_option('ouop');
    return isset($op[$k]) ? (isset($v) ? $op[$k] == $v : $op[$k]) : '';
}

function oss_upload_dir_loader(){
    add_filter('upload_dir', 'oss_upload_dir');
}

function oss_upload_check_handle(){
    if(!defined('OSS_ACCESS_ID')) return false;
    $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : (isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : '');
    return in_array($action, array('upload-plugin', 'upload-theme')) ? false : true;
}

function oss_upload_encode($str){
    return strtoupper(substr(PHP_OS,0,3)) == 'WIN' ? iconv('utf-8', 'gbk//IGNORE', $str) : $str;
}

function oss_upload_basename($file){
    return basename(wp_parse_url($file, PHP_URL_PATH));
}

function oss_upload_rename($name){
    if(!ouops('oss_rename')) return $name;
    $filetype = wp_check_filetype($name);
    $ext = !empty($filetype['ext']) ? $filetype['ext'] : 'png';
    return md5($name).'.'.$ext;
}

function oss_upload_webp(){
    if(!ouops('oss_webp') || wp_is_mobile()) return 0;
    $http_accept = isset($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])) : '';
    return stripos($http_accept, '/webp') !== false ? 1 : 0;
}

function oss_upload_dir($param){
    if(!oss_upload_check_handle()) return $param;
    if(ouops('oss') && ouops('oss_path') && ouops('oss_url')){
        $basedir = trim(ouops('oss_path'), '/');
        if(empty($param['default']) && $param['basedir'] != $basedir) $param['default'] = $param;
        $param['basedir'] = $basedir;
        $param['path'] = $param['basedir'] . $param['subdir'];
        $param['baseurl'] = trim(ouops('oss_url'), '/');
        $param['url'] = $param['baseurl'] . $param['subdir'];
    }
    return $param;
}

function oss_upload_readdir($dir, $r = false){
    if(!is_dir($dir)) return false;
    $files = array();
    $handle = scandir($dir);
    foreach($handle as $file){
        if($file == '.' || $file == '..') continue;
        $one = rtrim($dir,'/').'/'.$file;
        if(is_dir($one)){
            if($r && $n = oss_upload_readdir($one, $r)) $files = array_merge($files, $n);
        }else{
            $files[] = $one;
        }
    }
    return $files;
}

add_filter('wp_handle_upload_prefilter', 'oss_upload_handle_upload_prefilter');
function oss_upload_handle_upload_prefilter($file){
    if(!oss_upload_check_handle()) return $file;
    $upload = oss_upload_dir(wp_get_upload_dir());
    $newname = oss_upload_rename(oss_upload_encode($file['name']));
    $newname = wp_unique_filename($upload['default']['path'], $newname);
    $file['name'] = wp_unique_filename($upload['path'], $newname);
    if(isset($file['size']) && $file['size'] >= 1024*1024*2 && (stripos($file['type'],'image')!==0 || !ouops('oss_service',10))){
        remove_filter('upload_dir', 'oss_upload_dir');//upload via file
    }else if(ouops('oss_backup')){
        @copy($file['tmp_name'], $upload['default']['path'].'/'.$file['name']);//upload via stream
    }
    return $file;
}

add_filter('wp_handle_upload', 'oss_upload_handle_upload', 9999, 2);
function oss_upload_handle_upload($file, $context='upload'){
    if(!has_filter('upload_dir', 'oss_upload_dir')){
        oss_upload_handler($file['file']);
        oss_upload_dir_loader();
    }
    return $file;
}

function oss_upload_handler($file, $errdel=true){
    if(!oss_upload_check_handle()) return;
    $upload = oss_upload_dir(wp_get_upload_dir());
    $basedir = explode('/', substr($upload['basedir'].'/', 6), 2);
    $path = str_replace($upload['default']['basedir'].'/', '', $file);
    try{
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        $ossw = new OU_ALIOSS;
        $info = $ossw->create_mpu_object($basedir[0], $basedir[1].$path, array('fileUpload'=>$file));
        if(isset($_SESSION['oss_upload_error'])) unset($_SESSION['oss_upload_error']);
        if($info->isOK()) return $upload['basedir'].'/'.$path;
    }catch(Exception $ex){
        if($errdel && @file_exists($file)) wp_delete_file($file);
        $_SESSION['oss_upload_error'] = esc_html($file) .'<br/>'. esc_html($ex->getMessage());
    }
    return false;
}

function oss_upload_request_unsafe($args, $url){
    $args['reject_unsafe_urls'] = false;
    $args['headers'] = array('Referer'=>$url);
    return $args;
}

add_filter('filesystem_method', 'oss_upload_filesystem_method', 10, 4);
function oss_upload_filesystem_method($method, $args, $context, $ownership){
    return ouops('oss') ? 'direct' : $method;
}

add_filter('_wp_relative_upload_path', 'oss_upload_relative_path', 10, 2);
function oss_upload_relative_path($new_path, $path){
    if(ouops('oss') && oss_upload_check_handle()){
        $upload = wp_get_upload_dir();
        $new_path = str_replace(array($upload['basedir'].'/', $upload['default']['basedir'].'/'), '', $new_path);
    }
    return $new_path;
}

function oss_upload_privacy_exports_dir($dir){
    $upload = wp_get_upload_dir();
    return str_replace($upload['basedir'], $upload['default']['basedir'], $dir);
}

function oss_upload_privacy_exports_url($url){
    $upload = wp_get_upload_dir();
    return str_replace($upload['baseurl'], $upload['default']['baseurl'], $url);
}

add_action('after_setup_theme', 'oss_upload_after_setup_theme', 11);
function oss_upload_after_setup_theme(){
    if(($width = ouops('oss_size_width')) && ($height = ouops('oss_size_height'))){
        add_theme_support('post-thumbnails');
        set_post_thumbnail_size(intval($width), intval($height), array('center', 'center'));
    }
}


add_action('admin_init', 'oss_upload_admin_init', 1);
function oss_upload_admin_init() {
    register_setting('oss_upload_admin_options_group', 'ouop', array(
        'type' => 'array',
        'sanitize_callback' => 'oss_upload_sanitize_options',
    ));
    if(!ouops('oss')) return;
    if(isset($_GET['page'], $_GET['action']) && $_GET['page'] == 'oss-upload') oss_upload_admin_action();
    if(ouops('oss_hd_thumbnail')) add_filter('big_image_size_threshold', '__return_false');
   add_filter('wp_privacy_exports_dir', 'oss_upload_privacy_exports_dir');
    add_filter('wp_privacy_exports_url', 'oss_upload_privacy_exports_url');
}

function oss_upload_sanitize_options($input) {
    if (!is_array($input)) {
        return array();
    }

    $sanitized = array();

    // Sanitize checkbox values
    $checkboxes = array('oss', 'oss_webp', 'oss_lazyload', 'oss_backup', 'oss_remote',
                       'oss_upload_remote', 'oss_rename', 'oss_url_back', 'oss_gif', 'oss_hd_thumbnail');
    foreach ($checkboxes as $key) {
        $sanitized[$key] = isset($input[$key]) ? 1 : 0;
    }

    // Sanitize URLs
    if (isset($input['oss_path'])) {
        $sanitized['oss_path'] = sanitize_text_field($input['oss_path']);
    }
    if (isset($input['oss_url'])) {
        $sanitized['oss_url'] = esc_url_raw($input['oss_url']);
    }
    if (isset($input['oss_lazyurl'])) {
        $sanitized['oss_lazyurl'] = sanitize_text_field($input['oss_lazyurl']);
    }

    // Sanitize text fields
    $text_fields = array('oss_akey', 'oss_skey', 'oss_endpoint', 'oss_style_separator',
                        'oss_fullsize_style', 'upload_mimes', 'oss_url_find', 'oss_url_replace',
                        'oss_remote_white', 'oss_remote_black');
    foreach ($text_fields as $key) {
        if (isset($input[$key])) {
            $sanitized[$key] = sanitize_text_field($input[$key]);
        }
    }

    // Sanitize numeric fields
    if (isset($input['oss_service'])) {
        $sanitized['oss_service'] = absint($input['oss_service']);
    }
    if (isset($input['oss_quality'])) {
        $quality = absint($input['oss_quality']);
        $sanitized['oss_quality'] = ($quality >= 1 && $quality <= 99) ? $quality : 50;
    }
    if (isset($input['oss_size_width'])) {
        $sanitized['oss_size_width'] = absint($input['oss_size_width']);
    }
    if (isset($input['oss_size_height'])) {
        $sanitized['oss_size_height'] = absint($input['oss_size_height']);
    }

    return $sanitized;
}

add_action('admin_menu', 'oss_upload_admin_menu');
function oss_upload_admin_menu() {
    $menu = __('Aliyun OSS Upload','aliyun-oss-upload');
    add_options_page($menu, $menu, 'manage_options', 'aliyun-oss-upload', 'oss_upload_options_page');
}

add_filter('views_upload', 'oss_upload_views_upload');
function oss_upload_views_upload($views){
    $link = oss_upload_link('options-general.php?page=aliyun-oss-upload', __('Aliyun OSS Upload','aliyun-oss-upload'), 'button');
    if(is_super_admin()) $views['actions'] = $link;
    return $views;
}

add_filter('plugin_action_links_'.plugin_basename( __FILE__ ), 'oss_upload_settings_link');
function oss_upload_settings_link($links) {
    if(is_multisite() && (!is_main_site() || !is_super_admin())) return $links;
    $osslink = array(oss_upload_link('options-general.php?page=aliyun-oss-upload', __('Settings','aliyun-oss-upload')));
    return array_merge($osslink, $links);
}

add_action('update_option_ouop', 'oss_upload_update_options', 10, 3);
function oss_upload_update_options($old, $value, $option){
    if(is_multisite() && (!is_main_site() || !is_super_admin())) return;
    update_site_option($option, $value);
}

function oss_upload_data($key){
    $data = get_plugin_data( __FILE__ );
    return isset($data) && is_array($data) && isset($data[$key]) ? $data[$key] : '';
}

function oss_upload_show_more($cols, $ret=false){
    static $header = array();
    $arr  = get_user_option('managesettings_page_oss-uploadcolumnshidden');
    $hide = (is_array($arr) && in_array($cols, $arr)) ? ' hidden' : '';
    $head = in_array($cols, $header) ? " class='{$cols}" : " id='{$cols}' class='manage-column";
    $out = "{$head} column-{$cols}{$hide}'";
    if(!in_array($cols, $header)) $header[] = $cols;
    if($ret) return $out;
    echo esc_attr($out);
}

add_filter('manage_settings_page_oss-upload_columns', 'oss_upload_setting_columns');
function oss_upload_setting_columns($cols){
    $cols['_title'] = __('For Less','aliyun-oss-upload');
    $cols['oss_upload_desc'] = __('Descriptions', 'aliyun-oss-upload');
    $cols['oss_upload_example'] = __('Examples', 'aliyun-oss-upload');
    return $cols;
}

function oss_upload_link($url, $text='', $ext=''){
    if(empty($text)) $text = $url;
    $button = stripos($ext, 'button') !== false ? " class='button'" : "";
    $target = stripos($ext, 'blank') !== false ? " target='_blank'" : "";
    $link = "<a href='" . esc_url($url) . "'{$button}{$target}>" . wp_kses_post($text) . "</a>";
    return stripos($ext, 'p') !== false ? "<p>{$link}</p>" : "{$link} ";
}

add_action('wp_enqueue_scripts', 'oss_upload_enqueue', 9999);
function oss_upload_enqueue(){
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    if(!ouops('oss_lazyload') || empty($user_agent) || stripos($user_agent, 'MSIE') !== false) return;
    wp_enqueue_script('jquery.lazyload', plugins_url('/lib/lazyload.js', __FILE__), array('jquery'), '4.9.0', true);
}

function oss_upload_post_meta_boxes(){
    $screen = get_current_screen();
    if($screen->id == 'post' && method_exists($screen, 'is_block_editor') && $screen->is_block_editor()){
        add_meta_box('open_social_post_meta_class', __('Aliyun OSS Upload', 'aliyun-oss-upload'),
            'oss_upload_post_action', 'post', 'side', 'default');
    }
}

function oss_upload_post_action(){
    $post = __('Autosave remote images to OSS', 'aliyun-oss-upload');
    wp_nonce_field('oss_upload_remote_action', 'oss_upload_remote_nonce');
    echo '<div class="misc-pub-section"><label><input name="oss_upload_remote_hidden" type="hidden" value="1" /><input name="oss_upload_remote" type="checkbox" value="1" ' . checked(ouops('oss_remote'), 1, false) . ' /> ' . esc_html($post) . '</label></div>';
}

function oss_upload_post_save($content){
    global $post;
    if(empty($_POST['oss_upload_remote_hidden'])){
        if(!ouops('oss_upload_remote')) return $content;
    }else{
        if(empty($_POST['oss_upload_remote'])) return $content;
    }
    if(empty($post->ID) || !current_user_can('edit_post', $post->ID)) return $content;

    // Verify nonce
    if (!isset($_POST['oss_upload_remote_nonce']) || !wp_verify_nonce($_POST['oss_upload_remote_nonce'], 'oss_upload_remote_action')) {
        return $content;
    }
    $upload = wp_get_upload_dir();
    $default = substr($upload['default']['baseurl'], stripos($upload['default']['baseurl'], '//'));
    $baseurl = substr($upload['baseurl'], stripos($upload['baseurl'], '//'));
    $content = stripslashes($content);
    $white = trim(ouops('oss_remote_white'));
    $black = trim(ouops('oss_remote_black'));
    $white = ouops('oss_remote_white') ? explode(',', trim(ouops('oss_remote_white'))) : false;
    $black = ouops('oss_remote_black') ? explode(',', trim(ouops('oss_remote_black'))) : false;
    $check = preg_match_all('/<img.*?(?<=data-src|data-original|data-original-src)="(.*?)"[^>]+>/', $content, $mx);
    if($check || preg_match_all('/<img.*? src="(.*?)"[^>]+>/', $content, $mx)){
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        add_filter('http_request_args', 'oss_upload_request_unsafe', 11, 2);//for unsafe-image url
        $mxIndex = -1;
        foreach($mx[1] as $img){
            $mxIndex++;
            $white_match = $black_match = false;
            if(stripos($img, '//') === 0) $img = 'http:'.$img;
            if(!stripos($img, '://') || stripos($img, $default) || stripos($img, $baseurl)) continue;
            if($white){
                foreach ($white as $w) {
                    if(stripos($img, trim($w)) !== false){
                        $white_match = true;
                        break;
                    }
                }
                if(!$white_match) continue;
            }
            if($black){
                foreach ($black as $b) {
                    if(stripos($img, trim($b)) !== false){
                        $black_match = true;
                        break;
                    }
                }
                if($black_match) continue;
            }
            if(!pathinfo($img, 4)) $img .= '#?'.oss_upload_basename($img).'.png';//for unlikely-image url
            $desc = explode('#', pathinfo($img, 8));
            try{
                //$imgid = media_sideload_image($img, $post->ID, $desc[0], 'id');//one step without rename
                $tmpfile = download_url($img);
                if(!is_wp_error($tmpfile)){
                    preg_match('/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $img, $mxx);
                    $name = oss_upload_rename($mxx ? wp_basename($mxx[0]) : oss_upload_basename($img));
                    $file_array = array('name' => $name, 'tmp_name' => $tmpfile);
                    $imgid = media_handle_sideload($file_array, $post->ID, $desc[0]);
                    if(is_wp_error($imgid)) wp_delete_file($tmpfile);
                }
            }catch(Exception $ex){
                $imgid = '';
            }
            if(!empty($imgid) && !is_wp_error($imgid)){
                $imghtml = get_image_tag($imgid, $desc[0], 0, 'none', 'full');
                $content = str_replace($mx[0][$mxIndex], $imghtml, $content);
            }
        }
        remove_filter('http_request_args', 'oss_upload_request_unsafe', 11, 2);
    }
    return $content;
}

add_filter('the_content', 'oss_upload_content_webp', 9999);
function oss_upload_content_webp($content){
    if(!ouops('oss') && ouops('oss_url_back')){//no oss
        $ossurl = trim(ouops('oss_url'), '/');
        if(empty($ossurl)) return $content;
        $upload = wp_get_upload_dir();
        $localurl = isset($upload['default']) ? $upload['default']['baseurl'] : $upload['baseurl'];
        return str_replace($ossurl, $localurl, $content);
    }
    if(!ouops('oss') || ouops('oss_service',10) || (!oss_upload_webp() && !ouops('oss_lazyload'))) return $content;
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    if(!empty($user_agent) && preg_match('/msie|spider|bot/i', $user_agent)) return $content;
    return preg_replace_callback('/<img.*?src="([^"]+)"/', function($mx){
        return str_replace($mx[1], oss_upload_auto_webp($mx[1], ouops('oss_lazyload')), $mx[0]);
    }, $content);
}

add_filter('the_content', 'oss_upload_url_fixer', 99999);
function oss_upload_url_fixer($url){
    if(!ouops('oss') && !ouops('oss_url_back')) return $url;//with or without
    if($find = trim(ouops('oss_url_find'))){
        $find  = explode(',', $find);
        $replace  = explode(',', trim(ouops('oss_url_replace')));
        $url = str_replace($find, $replace, $url);
    }
    return $url;
}

add_filter('wp_generate_attachment_metadata', 'oss_upload_generate_metadata', 9999, 2);
function oss_upload_generate_metadata($data, $id){
    if(!ouops('oss')) return $data;
    if(!ouops('oss_service',10)) oss_upload_delete_thumbnail($id, $data);
    if(!ouops('oss_backup')){
        $upload = wp_get_upload_dir();
        $file = get_attached_file($id, 1);//unfilter
        $local = str_replace($upload['basedir'], $upload['default']['basedir'], $file);
        if(@file_exists($local)) @wp_delete_file($local);
    }
    return $data;
}

add_filter('wp_get_attachment_metadata', 'oss_upload_attachment_metadata', 9999, 2);
function oss_upload_attachment_metadata($data, $id){
    if(!ouops('oss') || empty($data['sizes'])) return $data;
    $service = ouops('oss_service');
    if($service == 10) return $data;
    if($service == 2 || (ouops('oss_lazyload') && !is_admin())) $data['sizes'] = array();
    $ouss = ouops('oss_style_separator') ? trim(ouops('oss_style_separator')) : '?x-oss-process=style%2F';
    $ext = wp_check_filetype(oss_upload_basename($data['file']));
    $gif = $ext && $ext['ext'] == 'gif' ? 1 : 0;
    $quality = ouops('oss_quality') ? intval(ouops('oss_quality')) : '50';
    $quality = $gif ? '' : '%2Fquality,q_'.$quality;
    foreach ($data['sizes'] as $k => $v){
        if(!isset($v['file'])) continue;
        if($gif && $service && ouops('oss_gif')) continue;
        $postfix = $service ? "{$ouss}{$k}" : "?x-oss-process=image{$quality}%2Fresize,m_fill,w_{$v['width']},h_{$v['height']}";
        $data['sizes'][$k]['file'] = oss_upload_basename($data['file']).$postfix;
    }
    return $data;
}

add_filter('wp_calculate_image_srcset', 'oss_upload_image_srcset', 9999, 5);
function oss_upload_image_srcset($sources, $size, $image_src, $meta, $id){//wp_get_attachment_image_srcset
    if(!ouops('oss') || empty($meta['sizes']) || empty($sources)) return $sources;
    $upload = wp_get_upload_dir();
    if(wp_parse_url(admin_url(), PHP_URL_SCHEME) == 'https'){
        $upload['default']['baseurl'] = set_url_scheme($upload['default']['baseurl'], 'https');
    }
    foreach ($sources as $k => $v){
        $url = str_replace($upload['default']['baseurl'], $upload['baseurl'], $sources[$k]['url']);
        $url = oss_upload_url_fixer($url);
        if(oss_upload_basename($meta['file']) == wp_basename($url)){//original
            if(ouops('oss_service',1) || ouops('oss_fullsize_style')){//style
                $ouss = ouops('oss_style_separator') ? trim(ouops('oss_style_separator')) : '?x-oss-process=style%2F';
                $full = ouops('oss_fullsize_style') ? trim(ouops('oss_fullsize_style')) : 'full';
                $url .= $ouss.$full;
            }
        }
        $sources[$k]['url'] = oss_upload_auto_webp($url);
    }
    return $sources;
}

add_filter('intermediate_image_sizes', 'oss_upload_intermediate_sizes', 999);
function oss_upload_intermediate_sizes($sizes){
    if(!ouops('oss_hd_thumbnail')) return $sizes;
    return array_merge(array_diff($sizes, array('1536x1536', '2048x2048')));
}

add_filter('intermediate_image_sizes_advanced', 'oss_upload_intermediate_sizes_advanced', 999);
function oss_upload_intermediate_sizes_advanced($sizes){
    if(!ouops('oss_hd_thumbnail')) return $sizes;
    unset($sizes['1536x1536']);
    unset($sizes['2048x2048']);
    return $sizes;
}

add_filter('wp_get_attachment_url', 'oss_upload_attachment_url', 9999, 2);
function oss_upload_attachment_url($url, $id){
    if(!ouops('oss') || !ouops('oss_url') || !oss_upload_check_handle()) return $url;
    $upload = wp_get_upload_dir();
    $find = $upload['default']['baseurl'];
    $replace = $upload['baseurl'];
    $url = oss_upload_url_fixer(str_replace($find, $replace, $url));
    if(ouops('oss_service',10)) return $url;
    $ext = wp_check_filetype(oss_upload_basename($url));
    if(!$ext || !in_array($ext['ext'], array('bmp','gif','png','jpg','jpe','jpeg'))) return $url;
    if($ext && $ext['ext'] == 'gif' && ouops('oss_gif')) return $url;
    if(ouops('oss_service',1) || ouops('oss_fullsize_style')){//style
        $ouss = ouops('oss_style_separator') ? trim(ouops('oss_style_separator')) : '?x-oss-process=style%2F';
        $full = ouops('oss_fullsize_style') ? trim(ouops('oss_fullsize_style')) : 'full';
        $url .= $ouss.$full;
    }
    if(!is_admin()) $url = oss_upload_auto_webp($url);
    return $url;
}

add_filter('get_attached_file', 'oss_upload_attached_file', 9999, 2);
function oss_upload_attached_file($file, $id){
    if(ouops('oss') && ouops('oss_path') && oss_upload_check_handle()){
        $upload = wp_get_upload_dir();
        $find = $upload['default']['basedir'];
        $replace = $upload['basedir'];
        $file = str_replace($find, $replace, $file);
    }
    return $file;
}

function oss_upload_auto_webp($img, $lazyload=false){
    if(!ouops('oss') || ouops('oss_service',1) || ouops('oss_service',10)) return $img;
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    if(!empty($user_agent) && preg_match('/spider|bot/i', $user_agent)) return $img;
    $upload = wp_get_upload_dir();
    $default = substr($upload['default']['baseurl'], stripos($upload['default']['baseurl'], '//'));
    $baseurl = substr($upload['baseurl'], stripos($upload['baseurl'], '//'));
    $img = str_replace($default, $baseurl, $img);//compatible with old link
    if(empty($img) || stripos($img, $baseurl) === false) return $img;
    $ouis = $lazy = $pos = '';
    if(stripos($img, '#')) $img = substr($img, 0, strripos($img, '#'));
    if($pos = stripos($img, '?x-oss-process=image')){
        $ouis = '%2Fformat,webp';
    }else if(!stripos($img, '?')){
        $ouis = '?x-oss-process=image%2Fformat,webp';
    }
    if($lazyload && !is_feed() && !wp_doing_ajax()){
        $lazy = empty($pos) ? $img : substr($img, 0, $pos);
        if($lazyurl = ouops('oss_lazyurl')){
            $lazy = str_replace('{IMG}', $lazy, $lazyurl);
        }else{
            $lazy .= '?x-oss-process=image%2Fquality,q_10%2Fresize,m_lfit,w_20';
            if(oss_upload_webp()) $lazy .= '%2Fformat,webp';
        }
    }
    if(oss_upload_webp() && !empty($ouis) && !stripos($img, $ouis)) $img .= $ouis;
    return empty($lazy) ? $img : $lazy.'" data-src="'.$img;
}

function oss_upload_delete_thumbnail($id, $data=array()){
    $arr = array();
    $upload = wp_get_upload_dir();
    if(empty($data)) $data = wp_get_attachment_metadata($id, 1);//unfilter
    if(empty($data) || empty($data['sizes'])) return $arr;
    foreach ($data['sizes'] as $k => $v){
        if(empty($v['file'])) continue;
        if(oss_upload_basename($data['file']) == oss_upload_basename($v['file'])) continue;
        $file = $upload['basedir'].'/'.dirname($data['file']).'/'.oss_upload_basename($v['file']);
        if(@file_exists($file) && wp_delete_file($file)) $arr[] = $file;
        if(!empty($upload['default'])){
            $file = $upload['default']['basedir'].'/'.dirname($data['file']).'/'.oss_upload_basename($v['file']);
            if(@file_exists($file) && wp_delete_file($file)) $arr[] = $file;
        }
    }
    return $arr;
}

add_action('delete_attachment', 'oss_upload_delete_attachment');
function oss_upload_delete_attachment($id){
    if(!ouops('oss') || !oss_upload_check_handle()) return;
    $arr = array();
    $upload = wp_get_upload_dir();
    if($file = get_post_meta($id, '_wp_attached_file', true)){
        $file = str_replace($upload['default']['basedir'].'/', '', $file);
        $arr[] = $upload['basedir'].'/'.$file;
        $arr[] = $upload['default']['basedir'].'/'.$file;
        $subdir = dirname($file);
        $file = get_post_meta($id, '_wp_attachment_backup_sizes', true);
        if(!empty($file)){
            foreach ($file as $k => $v){
                $arr[] = $upload['basedir'].'/'.$subdir.'/'.oss_upload_basename($v['file']);
                $arr[] = $upload['default']['basedir'].'/'.$subdir.'/'.oss_upload_basename($v['file']);
            }
        }
    }
    if(!empty($arr)) $arr = array_unique($arr);
    foreach ($arr as $k) { if(@file_exists($k)) wp_delete_file($k); }
    oss_upload_delete_thumbnail($id);
}

add_filter('wp_image_editors', 'oss_upload_image_editors');
function oss_upload_image_editors($arr){//WP_Image_Editor_Imagick might have problem with Stream
    return ouops('oss') ? array('WP_Image_Editor_GD', 'WP_Image_Editor_Imagick') : $arr;
}

add_filter('fallback_intermediate_image_sizes', 'oss_upload_intermediate_noimage', 10, 2);
function oss_upload_intermediate_noimage($sizes, $metadata){//non-image
    return ouops('oss') ? array(): $sizes;
}

add_filter('upload_mimes', 'oss_upload_upload_mimes', 99);
function oss_upload_upload_mimes($mimes){
    if($arr = trim(ouops('upload_mimes'))){
        $arr = explode(',', $arr);
        foreach($arr as $k){
            $kv = explode('=', trim($k));
            if(count($kv) == 2) $mimes[trim($kv[0])] = trim($kv[1]);
        }
    }
    return $mimes;
}

add_action('current_screen', 'oss_upload_setting_screen');
function oss_upload_setting_screen() {
    $screen = get_current_screen();
    if($screen->id != 'settings_page_oss-upload' || !ouops('oss')) return;
    $help_content = '<p>'.oss_upload_data('Description').'</p><br/><p>'.
        oss_upload_link('//promotion.aliyun.com/ntms/yunparter/invite.html?userCode=9ufcuiuf&utm_source=9ufcuiuf', __('Aliyun Coupon <span>NEW</span>', 'aliyun-oss-upload'), 'button,blank').
        oss_upload_link('//promotion.aliyun.com/ntms/act/oss-discount.html?userCode=9ufcuiuf&utm_source=9ufcuiuf', __('OSS Discount <span>HOT</span>', 'aliyun-oss-upload'), 'button,blank').
        oss_upload_link('//wordpress.org/plugins/oss-upload/', __('Rating Stars', 'aliyun-oss-upload'), 'button,blank').
        oss_upload_link(oss_upload_data('PluginURI'), __('Support and Help', 'aliyun-oss-upload'), 'button,blank').
        oss_upload_link('//www.imkarry.com/about', __('About Developer', 'aliyun-oss-upload'), 'button,blank');
    $help_sidebar = '<p><strong>'.__('About', 'aliyun-oss-upload').'</strong></p>'.
        oss_upload_link('//oss.console.aliyun.com/index', __('Aliyun OSS', 'aliyun-oss-upload'), 'p,blank').
        oss_upload_link('//help.aliyun.com/document_detail/32174.html', __('OSS PHP SDK', 'aliyun-oss-upload'), 'p,blank');
    $screen->add_help_tab(array('id'=>'oss_upload_help', 'title'=>__('For More', 'aliyun-oss-upload'), 'content'=>$help_content));
    $screen->set_help_sidebar($help_sidebar);
}

add_action('admin_enqueue_scripts', 'oss_upload_admin_enqueue');
function oss_upload_admin_enqueue($hook) {
    // Only load on our settings page
    if ($hook != 'settings_page_oss-upload') {
        return;
    }

    wp_enqueue_script('oss-upload-admin', plugins_url('/lib/admin-settings.js', __FILE__), array('jquery'), '4.9.0', true);

    // Localize script with confirmation messages
    wp_localize_script('oss-upload-admin', 'ossUploadL10n', array(
        'confirmClean' => __('This action would clean all thumbnails including local and OSS that filename like photo-800x600.png, cannot be undone, comfirm to process?', 'aliyun-oss-upload'),
        'confirmUpload' => __('This action would upload local storage directory to OSS, override if file exists, might take several minutes, comfirm to process?', 'aliyun-oss-upload'),
        'confirmSync' => __('This action would upload attachment from local storage that missing in OSS, might take several minutes, comfirm to process?', 'aliyun-oss-upload'),
        'confirmReset' => __('This action would regenerate metadata of all attachment in OSS, might take several minutes, comfirm to process?', 'aliyun-oss-upload'),
        'nonceClean' => wp_create_nonce('oss_upload_action_clean'),
        'nonceUpload' => wp_create_nonce('oss_upload_action_upload'),
        'nonceSync' => wp_create_nonce('oss_upload_action_sync'),
        'nonceReset' => wp_create_nonce('oss_upload_action_reset'),
    ));

    // Add inline style for metabox-prefs span
    wp_add_inline_style('wp-admin', '.metabox-prefs span {display: inline-block; vertical-align: text-bottom; margin: 1px 0 0 2px; padding: 0 5px; border-radius: 5px; background-color: #ca4a1f; color: #fff; font-size: 10px; line-height: 17px;}');
}

add_action('admin_notices', 'oss_upload_admin_note');
function oss_upload_admin_note(){
    $screen = get_current_screen();
    if($screen->id != 'settings_page_oss-upload' || !ouops('oss') || !is_super_admin()) return;
    if(isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'test'){
        try{
            $rnd = md5(time());
            $file = ouops('oss_path').'/oss_upload_'.$rnd.'.txt';
            $try = file_put_contents($file, $rnd);
            if($try == strlen($rnd)){
                $out = __('Write OK, ','aliyun-oss-upload');
                $try = file_get_contents($file);
                if($try == $rnd){
                    $out .= __('Read OK, ', 'aliyun-oss-upload');
                    $try = wp_delete_file($file);
                    if($try === true){
                        $out .= __('Delete OK', 'aliyun-oss-upload');
                        $ok = true;
                    }else{
                        throw new RequestCore_Exception($out . __('Delete Error: ', 'aliyun-oss-upload') . $try);
                    }
                }else{
                    throw new RequestCore_Exception($out . __('Read Error: ', 'aliyun-oss-upload') . $try);
                }
            }else{
                throw new RequestCore_Exception($out . __('Write Error: ', 'aliyun-oss-upload') . $try);
            }
        }catch(Exception $ex){
            $out = esc_html($ex->message);
        }
        if (!isset($ok)) {
            $ok = false;
        }

        if(isset($out)) {
            // 安全地使用 $ok
            $class = $ok ? 'updated fade' : 'error';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($out) . '</p></div>';
        }
    }
    if(isset($_SESSION['oss_upload_error'])){
        echo '<div class="error"><p>' . wp_kses_post($_SESSION['oss_upload_error']) . '</p></div>';
    }
}

function oss_upload_admin_action(){
    $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
    if(empty($action) || !is_super_admin()) return;

    // Add nonce verification
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'oss_upload_action_' . $action)) {
        wp_die(esc_html__('Security check failed', 'aliyun-oss-upload'));
    }

    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }
    ob_end_clean();
    echo esc_html(str_pad('',1024));
    echo '<title>' . esc_html__('Aliyun OSS Upload','aliyun-oss-upload') . '</title>';
    echo "<h1>" . esc_html__('Starting...', 'aliyun-oss-upload') . "</h1>\n";
    flush();
    $index = 1;
    $upload = wp_get_upload_dir();
    if($action == 'clean'){
        try{
            $files = get_posts(array('post_type'=>'attachment', 'posts_per_page'=>-1));
            $postfix = __('deleted', 'aliyun-oss-upload');
            $paths = array();
            foreach ($files as $file){
                $path = pathinfo(get_attached_file($file->ID), 1);
                if(!in_array($path, $paths)) $paths[] = $path;
                if(isset($_GET['force'])){
                    $path = pathinfo(get_attached_file($file->ID, 1), 1);
                    if(!in_array($path, $paths)) $paths[] = $path;
                }
                if($arr = oss_upload_delete_thumbnail($file->ID)){
                    foreach ($arr as $v){
                        echo esc_html($index++) . ". " . esc_html($v) . " " . esc_html($postfix) . "<br/>\n";
                        flush();
                    }
                }
            }
            foreach ($paths as $path){
                if(empty($path)) continue;
                $imgs = oss_upload_readdir($path);
                if(empty($imgs)) continue;
                foreach ($imgs as $img) {
                    if(preg_match('/\-[0-9]+x[0-9]+\./', $img) && file_is_valid_image($img)){
                        if(@file_exists($img) && wp_delete_file($img)){
                            echo esc_html($index++) . ". " . esc_html($img) . " " . esc_html($postfix) . "<br/>\n";
                            flush();
                        }
                    }
                }
            }
            if($index == 1){
                echo esc_html__('No thumbnail found','aliyun-oss-upload');
            }else{
                echo '<br/><hr/>';
                echo esc_html__('Clean thumbnails done','aliyun-oss-upload');
            }
        }catch(Exception $ex){
            echo esc_html($ex->getMessage());
        }
    }else if($action == 'upload'){
        $basedir = explode('/', substr($upload['basedir'].'/', 6), 2);
        try{
            $ossw = new OU_ALIOSS;
            $ossw->create_mtu_object_by_dir($basedir[0], $upload['default']['basedir'], true);
            echo '<br/><hr/>';
            echo esc_html__('Upload local storage to OSS done', 'aliyun-oss-upload');
        }catch(Exception $ex){
            echo esc_html($ex->getMessage());
        }
    }else if($action == 'sync'){
        $files = get_posts(array('post_type'=>'attachment', 'posts_per_page'=>-1));
        $postfix = __('synced', 'aliyun-oss-upload');
        foreach ($files as $file){
            $oss = get_attached_file($file->ID);
            $local = str_replace($upload['basedir'], $upload['default']['basedir'], $oss);
            if(@file_exists($local) && !@file_exists($oss) && ($done = oss_upload_handler($local))){
                echo esc_html($index++) . ". " . esc_html($done) . " " . esc_html($postfix) . "<br/>\n";
                flush();
            }
        }
        if($index == 1){
            echo esc_html__('No attachments need to be synced','aliyun-oss-upload');
        }else{
            echo '<br/><hr/>';
            echo esc_html__('Sync missing attachments to OSS done','aliyun-oss-upload');
        }
    }else if($action == 'reset'){
        // Only increase memory if absolutely necessary and not globally
        if ( function_exists( 'ini_set' ) ) {
            @ini_set( 'memory_limit', '2048M' );
        }
        $files = get_posts(array('post_type'=>'attachment', 'posts_per_page'=>-1));
        $postfix = __('reset', 'aliyun-oss-upload');
        foreach ($files as $file){
            if(!wp_attachment_is_image($file->ID)) continue;
            $img = get_attached_file($file->ID);
            $metadata = wp_generate_attachment_metadata($file->ID, $img);
            wp_update_attachment_metadata($file->ID, $metadata);
            echo esc_html($index++) . ". " . esc_html($file->ID) . " " . esc_html($img) . " " . esc_html($postfix) . "<br/>\n";
            flush();
        }
        echo '<br/><hr/>';
        echo esc_html__('Reset attachments metadata done','aliyun-oss-upload');
    }
    flush();
    exit();
}

function oss_upload_options_page(){
    $upload = wp_get_upload_dir();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Aliyun OSS Upload','aliyun-oss-upload')?>
            <a class="page-title-action" href="<?php echo esc_url(oss_upload_data('PluginURI'));?>" target="_blank"><?php echo esc_html(oss_upload_data('Version'));?></a>
        </h1>
        <form action="options.php" method="post">
        <?php settings_fields('oss_upload_admin_options_group'); ?>
        <table class="form-table">
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Enable','aliyun-oss-upload')?></th>
        <td>
            <label><input name="ouop[oss]" type="checkbox" value="1" <?php checked(ouops('oss'),1);?> />
            <?php esc_html_e('Use OSS as media library storage','aliyun-oss-upload')?></label>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Access Key','aliyun-oss-upload')?></th>
        <td>
            <input type="text" name="ouop[oss_akey]" size="60" placeholder="Access Key" value="<?php echo esc_attr(ouops('oss_akey'))?>" required />
            <?php echo wp_kses_post(oss_upload_link('//ak-console.aliyun.com/', '?', 'blank')); ?>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Secret Key','aliyun-oss-upload')?></th>
        <td>
            <input type="password" name="ouop[oss_skey]" size="60" placeholder="Secret Key" value="<?php echo esc_attr(ouops('oss_skey'))?>" required />
            <?php echo wp_kses_post(oss_upload_link('//ak-console.aliyun.com/', '?', 'blank')); ?>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Upload Path','aliyun-oss-upload')?></th>
        <td>
            <input type="url" name="ouop[oss_path]" size="60" placeholder="oss://{BUCKET}/{PATH}" value="<?php echo esc_attr(rtrim(ouops('oss_path'), '/'));?>" required />
            <?php echo wp_kses_post(oss_upload_link('//help.aliyun.com/document_detail/31902.html', '?', 'blank')); ?>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('<code>{BUCKET}</code> is Bucket name, <code>{PATH}</code> can be empty, with no slash at the end','aliyun-oss-upload')?></small></p>
            <div <?php oss_upload_show_more('oss_upload_example'); ?>>
            <p><small><code>oss://my-bucket</code></small></p>
            <p><small><code>oss://my-bucket/uploads</code></small></p>
            </div>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Visit URL','aliyun-oss-upload')?></th>
        <td>
            <input type="url" name="ouop[oss_url]" size="60" placeholder="http://oss.aliyuncs.com/{BUCKET}/{PATH}" value="<?php echo esc_attr(rtrim(ouops('oss_url'), '/'));?>" required />
            <?php echo wp_kses_post(oss_upload_link('//help.aliyun.com/document_detail/31902.html', '?', 'blank')); ?>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('<code>{BUCKET}</code> can be directory or domain, <code>{PATH}</code> can be empty','aliyun-oss-upload')?></small></p>
            <div <?php oss_upload_show_more('oss_upload_example'); ?>>
            <p><small><code>http://my-bucket.oss-cn-shenzhen.aliyuncs.com</code></small></p>
            <p><small><code>http://my-bucket.oss-cn-shenzhen.aliyuncs.com/uploads</code></small></p>
            <p><small><code>http://www.my-oss-domain.com</code></small></p>
            <p><small><code>https://www.my-oss-domain.com/uploads</code></small></p>
            <p><small><code>https://img.my-oss-domain.com</code></small></p>
            </div>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Upload EndPoint','aliyun-oss-upload')?></th>
        <td>
            <input type="text" name="ouop[oss_endpoint]" size="60" placeholder="oss-cn-hangzhou.aliyuncs.com" value="<?php echo esc_attr(ouops('oss_endpoint'))?>" required />
            <?php echo wp_kses_post(oss_upload_link('//help.aliyun.com/document_detail/31837.html', '?', 'blank')); ?>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Endpoint of your Bucket, can be internal address if WEB SERVER is in the same area with OSS','aliyun-oss-upload')?></small></p>
            <div <?php oss_upload_show_more('oss_upload_example'); ?>>
            <p><small><code>oss-cn-hangzhou.aliyuncs.com</code></small></p>
            <p><small><code>oss-cn-shenzhen.aliyuncs.com</code></small></p>
            <p><small><code>oss-cn-shanghai.aliyuncs.com</code></small></p>
            <p><small><code>oss-us-west-1.aliyuncs.com</code></small></p>
            <p><small><code>oss-cn-hangzhou-internal.aliyuncs.com</code></small></p>
            </div>
        </td></tr>
        <tr valign="top">
        <th scope="row"></th>
        <td>
            <?php
            if(ouops('oss') && ouops('oss_akey') && ouops('oss_skey') && ouops('oss_endpoint')){
                echo wp_kses_post(oss_upload_link('options-general.php?page=aliyun-oss-upload&settings-updated=test', __('Run a test', 'aliyun-oss-upload'), 'p,button'));
            } ?>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Image Thumbnails','aliyun-oss-upload')?></th>
        <td>
            <p><label><input name="ouop[oss_service]" type="radio" value="0" <?php checked(ouops('oss_service'),0);?> /> <?php esc_html_e('Use Image Service via Parameter, default and simple','aliyun-oss-upload')?></label>
            <?php echo wp_kses_post(oss_upload_link('//help.aliyun.com/document_detail/44688.html', '?', 'blank')); ?></p>
            <p <?php oss_upload_show_more('oss_upload_example'); ?>><small><code>photo.jpg?x-oss-process=image%2Fquality,q_<?php echo ouops('oss_quality') ? intval(ouops('oss_quality')) : '50'; ?>%2Fresize,m_fill,w_{width},h_{height}</code></small></p><br/>
            <p><label><input name="ouop[oss_service]" type="radio" value="1" <?php checked(ouops('oss_service'),1);?> /> <?php esc_html_e('Use Image Service via Style, powerful but require styles setting on OSS','aliyun-oss-upload')?></label>
            <?php echo wp_kses_post(oss_upload_link('//help.aliyun.com/document_detail/44687.html', '?', 'blank')); ?></p>
            <p <?php oss_upload_show_more('oss_upload_example'); ?>><small><code>photo.jpg<?php echo esc_html(ouops('oss_style_separator') ? trim(ouops('oss_style_separator')) : '?x-oss-process=style%2F'); ?>{style}</code>:
            <?php foreach (get_intermediate_image_sizes() as $v){ echo '<code>'.esc_html($v).'</code> '; } ?>
            </small></p><br/>
            <p><label><input name="ouop[oss_service]" type="radio" value="10" <?php checked(ouops('oss_service'),10);?> /> <?php esc_html_e('Use physical thumbnails, check this when having problem with theme','aliyun-oss-upload')?></label></p>
            <p <?php oss_upload_show_more('oss_upload_example'); ?>><small><code>photo-{width}x{height}.jpg</code></small></p><br/>
            <p><label><input name="ouop[oss_service]" type="radio" value="2" <?php checked(ouops('oss_service'),2);?> /> <?php esc_html_e('Disable image thumbnails','aliyun-oss-upload')?></label></p>
            <p <?php oss_upload_show_more('oss_upload_example'); ?>><small><code>photo.jpg</code></small></p><br/>
            <p><?php
                echo wp_kses_post(oss_upload_link('options-media.php', __('Media Sizes Options', 'aliyun-oss-upload'), 'button'));
                echo wp_kses_post(oss_upload_link('?page=aliyun-oss-upload&action=clean', __('Clean Thumbnails', 'aliyun-oss-upload'), 'button,blank'));
                if(!ouops('oss_service',2)) echo wp_kses_post(oss_upload_link('?page=aliyun-oss-upload&action=reset', __('Regenerate Thumbnails', 'aliyun-oss-upload'), 'button,blank'));
            ?></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Thumbnail Quality', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input type="number" name="ouop[oss_quality]" size="10" min="1" max="99" placeholder="15" value="<?php echo esc_attr(ouops('oss_quality'))?>" /></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Set the quality of thumbnail for OSS Image Servie to speed up image loading, the smaller the faster', 'aliyun-oss-upload');?>: <code>1 ~ 99</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Featured Image', 'aliyun-oss-upload')?></th>
        <td>
            <p><label>
                <input type="text" name="ouop[oss_size_width]" size="10" value="<?php echo esc_attr(ouops('oss_size_width'))?>" /> x
                <input type="text" name="ouop[oss_size_height]" size="10" value="<?php echo esc_attr(ouops('oss_size_height'))?>" />
            </label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Set the featured image dimensions when thumbnails enabled (width x height)', 'aliyun-oss-upload');?>: <code>800</code> x <code>450</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('HD Thumbnails', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input name="ouop[oss_hd_thumbnail]" type="checkbox" value="1" <?php checked(ouops('oss_hd_thumbnail'),1);?> />
            <?php esc_html_e('Disable <code>1356x1356</code>,<code>2048x2048</code> sizes when generate thumbnails','aliyun-oss-upload')?></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Disable the whole high definition resolution things come with WordPress 5.3 like <code>image-scaled.png</code>', 'aliyun-oss-upload');?></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Style Separator', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input type="text" name="ouop[oss_style_separator]" size="60" value="<?php echo esc_attr(ouops('oss_style_separator'))?>" /> <?php echo wp_kses_post(oss_upload_link('//help.aliyun.com/document_detail/48884.html', '?', 'blank')); ?></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Custom style separator for OSS Image Service style','aliyun-oss-upload')?>: <code>?x-oss-process=style%2F</code> <code>-</code> <code>_</code> <code>!</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Fullsize Style', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input type="text" name="ouop[oss_fullsize_style]" size="60" value="<?php echo esc_attr(ouops('oss_fullsize_style'))?>" />
            <?php echo wp_kses_post(oss_upload_link('//help.aliyun.com/document_detail/44686.html', '?', 'blank')); ?></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Default full size image style for OSS Image Service','aliyun-oss-upload')?>: <code>full</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('GIF Style', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input name="ouop[oss_gif]" type="checkbox" value="1" <?php checked(ouops('oss_gif'),1);?> />
            <?php esc_html_e('Using special OSS Image Service style for <code>GIF</code> format','aliyun-oss-upload')?> <?php echo wp_kses_post(oss_upload_link('//help.aliyun.com/document_detail/44957.html', '?', 'blank')); ?></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Check this to skip style for GIF image if having no animation effect','aliyun-oss-upload')?>
            </small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Auto Compress', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input name="ouop[oss_webp]" type="checkbox" value="1" <?php checked(ouops('oss_webp'),1);?> />
            <?php esc_html_e('Compress as <code>WebP</code> format automatically if browser support','aliyun-oss-upload')?> <?php echo wp_kses_post(oss_upload_link('//help.aliyun.com/document_detail/44703.html', '?', 'blank')); ?></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Choose webp format on OSS if using styles for Image Service','aliyun-oss-upload')?></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Lazyload', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input name="ouop[oss_lazyload]" type="checkbox" value="1" <?php checked(ouops('oss_lazyload'),1);?> />
            <?php esc_html_e('Delay loading of images in long web pages','aliyun-oss-upload')?>
            <?php echo wp_kses_post(oss_upload_link('//plugins.jquery.com/lazyload/', '?', 'blank')); ?></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Images outside of viewport wont be loaded before user scrolls to them','aliyun-oss-upload')?></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Lazyload URL', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input type="text" name="ouop[oss_lazyurl]" size="60" value="<?php echo esc_attr(ouops('oss_lazyurl'))?>" /></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Default image url for lazyload, could be with Image Service suffix, or base64 data, or normal url. <code>{IMG}</code> means original','aliyun-oss-upload')?></small></p>
            <div <?php oss_upload_show_more('oss_upload_example'); ?>>
            <p><small><code>{IMG}?x-oss-process=image%2Fquality,q_10%2Fresize,m_lfit,w_20</code></small></p>
            <p><small><code>{IMG}<?php echo esc_html(ouops('oss_style_separator') ? trim(ouops('oss_style_separator')) : '?x-oss-process=style%2F'); ?>lazyload-style</code></small></p>
            <p><small><code>data:image/gif;base64,R0lGODdhAQABAPAAAMPDwwAAACwAAAAAAQABAAACAkQBADs=</code></small></p>
            <p><small><code>//img.domain.com/xxx/lazyload.png</code></small></p>
            </div>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Upload Mimes', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input type="text" name="ouop[upload_mimes]" size="60" value="<?php echo esc_attr(ouops('upload_mimes'))?>" />
                <?php echo wp_kses_post(oss_upload_link('//codex.wordpress.org/Function_Reference/get_allowed_mime_types', '?', 'blank')); ?></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Add file extensions and mime types to the allowed upload list','aliyun-oss-upload')?>: <code>flac=audio/x-flac,py=text/x-python</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Auto Rename', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input name="ouop[oss_rename]" type="checkbox" value="1" <?php checked(ouops('oss_rename'),1);?> />
            <?php esc_html_e('Auto rename uploaded file if having like Non-ASCII problem','aliyun-oss-upload')?></label></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('URL Fixer', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input name="ouop[oss_url_back]" type="checkbox" value="1" <?php checked(ouops('oss_url_back'),1);?> />
            <?php esc_html_e('Auto relocate attachments in past posts when OSS disabled','aliyun-oss-upload')?></label></p><br/>
            <p><label><input type="text" name="ouop[oss_url_find]" size="60" value="<?php echo esc_attr(ouops('oss_url_find'))?>" /></label></p>
            <p><label><input type="text" name="ouop[oss_url_replace]" size="60" value="<?php echo esc_attr(ouops('oss_url_replace'))?>" /></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Find and replace whatever strings you want to fix the attachment url','aliyun-oss-upload')?>: <code>http,upload</code> <code>https,uploads</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Remote Image', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input name="ouop[oss_remote]" type="checkbox" value="1" <?php checked(ouops('oss_remote'),1);?> />
            <?php esc_html_e('Enable remote images autosave when edit post/page','aliyun-oss-upload')?></label></p><br/>
            <p><label><input name="ouop[oss_upload_remote]" type="checkbox" value="1" <?php checked(ouops('oss_upload_remote'),1);?> />
            <?php esc_html_e('Enable remote images autosave when import post/page','aliyun-oss-upload')?></label></p><br/>
            <p><label><input type="text" name="ouop[oss_remote_white]" size="60" value="<?php echo esc_attr(ouops('oss_remote_white'))?>" /></label></p>
            <p><label><input type="text" name="ouop[oss_remote_black]" size="60" value="<?php echo esc_attr(ouops('oss_remote_black'))?>" /></label></p>
            <p <?php oss_upload_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Whitelist / Blacklist rules for remote images autosave','aliyun-oss-upload')?>: <code>jianshu.io</code> <code>noimg.com,icon.com</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Local Backup', 'aliyun-oss-upload')?></th>
        <td>
            <p><label><input name="ouop[oss_backup]" type="checkbox" value="1" <?php checked(ouops('oss_backup'),1);?> />
            <?php esc_html_e('Backup original image to local storage','aliyun-oss-upload')?> <small><code>
            <?php
                echo esc_html(isset($upload['default']['basedir']) ? $upload['default']['basedir'] : $upload['basedir']);
            ?>
            </code></small></label></p><br />
            <?php
                echo wp_kses_post(oss_upload_link('?page=aliyun-oss-upload&action=sync', __('Upload Missing Attachment', 'aliyun-oss-upload'), 'button,blank'));
                echo wp_kses_post(oss_upload_link('?page=aliyun-oss-upload&action=upload', __('Upload Whole Local Storage', 'aliyun-oss-upload'), 'button,blank'));
            ?>
        </td></tr>
        </table>
        <?php submit_button();?>
    </div>
    <?php
}

?>