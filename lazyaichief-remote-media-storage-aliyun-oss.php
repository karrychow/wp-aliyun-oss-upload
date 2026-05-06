<?php
/*
 * Plugin Name: LazyAIChief Remote Media Storage with Aliyun OSS
 * Version: 4.9.0
 * Description: Upload with Aliyun OSS, with modified OSS Wrapper and fully native image edit function support.
 * Plugin URI: https://github.com/karrychow/wp-aliyun-oss-upload
 * Author: Karry
 * Author URI: https://github.com/karrychow
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lazyaichief-remote-media-storage-aliyun-oss
 * Domain Path: /lang
 * Network: true
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'KRMOS_OPTION_KEY' ) ) {
    define( 'KRMOS_OPTION_KEY', 'lazyaichief_rmso_options' );
}

if ( ! defined( 'KRMOS_OSS_STREAM_SCHEME' ) ) {
    define( 'KRMOS_OSS_STREAM_SCHEME', 'oss' );
}

add_action('init', 'krmos_init', 1);
function krmos_init(){
    if(!krmso_get_option('oss') || !krmso_get_option('oss_akey') || !krmso_get_option('oss_skey') || !krmso_get_option('oss_endpoint') || !krmso_get_option('oss_path')) return;
    if ( ! defined( 'KRMOS_OSS_ACCESS_ID' ) ) {
        define('KRMOS_OSS_ACCESS_ID', trim(krmso_get_option('oss_akey')));
    }
    if ( ! defined( 'KRMOS_OSS_ACCESS_KEY' ) ) {
        define('KRMOS_OSS_ACCESS_KEY', trim(krmso_get_option('oss_skey')));
    }
    if ( ! defined( 'KRMOS_OSS_ENDPOINT' ) ) {
        define('KRMOS_OSS_ENDPOINT', trim(krmso_get_option('oss_endpoint')));
    }

    // Load Aliyun SDK and Adapter
    if (file_exists(dirname(__FILE__).'/lib/alibabacloud-oss-php-sdk-v2-0.4.0/autoload.php')) {
        require_once 'lib/alibabacloud-oss-php-sdk-v2-0.4.0/autoload.php';
    }
    require_once('lib/OU_ALIOSS_Adapter.php');
    require_once('lib/OSSWrapper.php');

    krmos_dir_loader();
    add_action('post_submitbox_misc_actions', 'krmos_post_action');
    add_action('add_meta_boxes', 'krmos_post_meta_boxes');
    add_filter('content_save_pre', 'krmos_post_save');
}

function krmso_get_option($k, $v=null){
    $op = get_option( KRMOS_OPTION_KEY );
    if ( ! $op ) {
        $op = get_site_option( KRMOS_OPTION_KEY );
    }
    return isset($op[$k]) ? (isset($v) ? $op[$k] == $v : $op[$k]) : '';
}

function krmso_get_backup_upload_dir() {
    $upload = wp_get_upload_dir();
    $default_basedir = ! empty( $upload['default']['basedir'] ) ? $upload['default']['basedir'] : $upload['basedir'];
    $default_baseurl = ! empty( $upload['default']['baseurl'] ) ? $upload['default']['baseurl'] : $upload['baseurl'];
    $subdir          = ! empty( $upload['subdir'] ) ? $upload['subdir'] : '';
    $folder          = 'lazyaichief-remote-media-storage-aliyun-oss';
    $basedir         = trailingslashit( $default_basedir ) . $folder;
    $baseurl         = trailingslashit( $default_baseurl ) . $folder;
    $path            = $basedir . $subdir;
    $url             = $baseurl . $subdir;

    if ( ! wp_mkdir_p( $path ) ) {
        return false;
    }

    return array(
        'basedir' => $basedir,
        'baseurl' => $baseurl,
        'subdir'  => $subdir,
        'path'    => $path,
        'url'     => $url,
    );
}

function krmso_escape_content_url( $url ) {
    return esc_url( $url, array_merge( wp_allowed_protocols(), array( 'data' ) ) );
}

function krmso_get_upload_mime_allowlist() {
    return array(
        'csv'  => array( 'text/csv', 'application/csv', 'text/plain' ),
        'flac' => array( 'audio/flac', 'audio/x-flac' ),
        'm4a'  => array( 'audio/mp4', 'audio/x-m4a' ),
        'vtt'  => array( 'text/vtt' ),
        'webm' => array( 'video/webm', 'audio/webm' ),
    );
}

function krmso_replace_url_value( $value ) {
    if ( ! krmso_get_option( 'oss' ) && ! krmso_get_option( 'oss_url_back' ) ) {
        return $value;
    }

    $find = trim( krmso_get_option( 'oss_url_find' ) );
    if ( empty( $find ) ) {
        return $value;
    }

    $find    = array_map( 'trim', explode( ',', $find ) );
    $replace = array_map( 'trim', explode( ',', trim( krmso_get_option( 'oss_url_replace' ) ) ) );

    return str_replace( $find, $replace, $value );
}

function krmso_replace_content_url_attributes_with_map( $content, $find, $replace ) {
    $replace_value = function ( $value ) use ( $find, $replace ) {
        return str_replace( $find, $replace, $value );
    };

    $content = preg_replace_callback(
        '/\b(src|href|poster|data-src|data-original|data-original-src)=("|\')(.*?)\2/i',
        function ( $matches ) use ( $replace_value ) {
            $value = krmso_escape_content_url( $replace_value( $matches[3] ) );
            return $matches[1] . '=' . $matches[2] . $value . $matches[2];
        },
        $content
    );

    return preg_replace_callback(
        '/\bsrcset=("|\')(.*?)\1/i',
        function ( $matches ) use ( $replace_value ) {
            $sources = array_map( 'trim', explode( ',', $matches[2] ) );
            foreach ( $sources as $index => $source ) {
                if ( '' === $source ) {
                    continue;
                }

                $parts          = preg_split( '/\s+/', $source, 2 );
                $parts[0]       = krmso_escape_content_url( $replace_value( $parts[0] ) );
                $sources[$index] = trim( implode( ' ', array_filter( $parts, 'strlen' ) ) );
            }

            return 'srcset=' . $matches[1] . esc_attr( implode( ', ', $sources ) ) . $matches[1];
        },
        $content
    );
}

function krmso_replace_srcset_value( $value ) {
    $sources = array_map( 'trim', explode( ',', $value ) );
    foreach ( $sources as $index => $source ) {
        if ( '' === $source ) {
            continue;
        }

        $parts       = preg_split( '/\s+/', $source, 2 );
        $parts[0]    = krmso_escape_content_url( krmso_replace_url_value( $parts[0] ) );
        $sources[$index] = trim( implode( ' ', array_filter( $parts, 'strlen' ) ) );
    }

    return implode( ', ', $sources );
}

function krmso_replace_content_url_attributes( $content ) {
    $content = preg_replace_callback(
        '/\b(src|href|poster|data-src|data-original|data-original-src)=("|\')(.*?)\2/i',
        function ( $matches ) {
            $value = krmso_escape_content_url( krmso_replace_url_value( $matches[3] ) );
            return $matches[1] . '=' . $matches[2] . $value . $matches[2];
        },
        $content
    );

    return preg_replace_callback(
        '/\bsrcset=("|\')(.*?)\1/i',
        function ( $matches ) {
            $value = esc_attr( krmso_replace_srcset_value( $matches[2] ) );
            return 'srcset=' . $matches[1] . $value . $matches[1];
        },
        $content
    );
}

function krmos_dir_loader(){
    add_filter('upload_dir', 'krmos_dir');
}

function krmos_check_handle(){
    if(!defined('KRMOS_OSS_ACCESS_ID')) return false;
    // phpcs:ignore WordPress.Security.NonceVerification
    $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : (isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : '');
    return in_array($action, array('upload-plugin', 'upload-theme')) ? false : true;
}

function krmos_encode($str){
    return strtoupper(substr(PHP_OS,0,3)) == 'WIN' ? iconv('utf-8', 'gbk//IGNORE', $str) : $str;
}

function krmos_basename($file){
    return basename(wp_parse_url($file, PHP_URL_PATH));
}

function krmos_rename($name){
    if(!krmso_get_option('oss_rename')) return $name;
    $filetype = wp_check_filetype($name);
    $ext = !empty($filetype['ext']) ? $filetype['ext'] : 'png';
    return md5($name).'.'.$ext;
}

function krmos_webp(){
    if(!krmso_get_option('oss_webp') || wp_is_mobile()) return 0;
    $http_accept = isset($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])) : '';
    return stripos($http_accept, '/webp') !== false ? 1 : 0;
}

function krmos_dir($param){
    if(!krmos_check_handle()) return $param;
    if(krmso_get_option('oss') && krmso_get_option('oss_path') && krmso_get_option('oss_url')){
        $basedir = trim(krmso_get_option('oss_path'), '/');
        if(empty($param['default']) && $param['basedir'] != $basedir) $param['default'] = $param;
        $param['basedir'] = $basedir;
        $param['path'] = $param['basedir'] . $param['subdir'];
        $param['baseurl'] = trim(krmso_get_option('oss_url'), '/');
        $param['url'] = $param['baseurl'] . $param['subdir'];
    }
    return $param;
}

function krmos_readdir($dir, $r = false){
    if(!is_dir($dir)) return false;
    $files = array();
    $handle = scandir($dir);
    foreach($handle as $file){
        if($file == '.' || $file == '..') continue;
        $one = rtrim($dir,'/').'/'.$file;
        if(is_dir($one)){
            if($r && $n = krmos_readdir($one, $r)) $files = array_merge($files, $n);
        }else{
            $files[] = $one;
        }
    }
    return $files;
}

add_filter('wp_handle_upload_prefilter', 'krmos_handle_upload_prefilter');
function krmos_handle_upload_prefilter($file){
    if(!krmos_check_handle()) return $file;
    $upload = krmos_dir(wp_get_upload_dir());
    $newname = krmos_rename(krmos_encode($file['name']));
    $newname = wp_unique_filename($upload['default']['path'], $newname);
    $file['name'] = wp_unique_filename($upload['path'], $newname);
    if(isset($file['size']) && $file['size'] >= 1024*1024*2 && (stripos($file['type'],'image')!==0 || !krmso_get_option('oss_service',10))){
        remove_filter('upload_dir', 'krmos_dir');//upload via file
    }else if(krmso_get_option('oss_backup')){
        $backup = krmso_get_backup_upload_dir();
        if ( $backup ) {
            @copy($file['tmp_name'], trailingslashit( $backup['path'] ) . $file['name']);//upload via stream
        }
    }
    return $file;
}

add_filter('wp_handle_upload', 'krmos_handle_upload', 9999, 2);
function krmos_handle_upload($file, $context='upload'){
    if(!has_filter('upload_dir', 'krmos_dir')){
        krmos_handler($file['file']);
        krmos_dir_loader();
    }
    return $file;
}

function krmos_handler($file, $errdel=true){
    if(!krmos_check_handle()) return;
    $upload = krmos_dir(wp_get_upload_dir());
    $basedir = explode('/', substr($upload['basedir'].'/', 6), 2);
    $path = str_replace($upload['default']['basedir'].'/', '', $file);
    try{
// if ( function_exists( 'set_time_limit' ) ) {
        //     @set_time_limit( 0 );
        // }
        $ossw = new KRMOS_OSS_Adapter;
        $info = $ossw->create_mpu_object($basedir[0], $basedir[1].$path, array('fileUpload'=>$file));
        if(isset($_SESSION['oss_upload_error'])) unset($_SESSION['oss_upload_error']);
        if($info->isOK()) return $upload['basedir'].'/'.$path;
    }catch(Exception $ex){
        if($errdel && @file_exists($file)) wp_delete_file($file);
        $_SESSION['oss_upload_error'] = esc_html($file) .'<br/>'. esc_html($ex->getMessage());
    }
    return false;
}

function krmos_request_unsafe($args, $url){
    $args['reject_unsafe_urls'] = false;
    $args['headers'] = array('Referer'=>$url);
    return $args;
}

add_filter('filesystem_method', 'krmos_filesystem_method', 10, 4);
function krmos_filesystem_method($method, $args, $context, $ownership){
    return krmso_get_option('oss') ? 'direct' : $method;
}

add_filter('_wp_relative_upload_path', 'krmos_relative_path', 10, 2);
function krmos_relative_path($new_path, $path){
    if(krmso_get_option('oss') && krmos_check_handle()){
        $upload = wp_get_upload_dir();
        $new_path = str_replace(array($upload['basedir'].'/', $upload['default']['basedir'].'/'), '', $new_path);
    }
    return $new_path;
}

function krmos_privacy_exports_dir($dir){
    $upload = wp_get_upload_dir();
    return str_replace($upload['basedir'], $upload['default']['basedir'], $dir);
}

function krmos_privacy_exports_url($url){
    $upload = wp_get_upload_dir();
    return str_replace($upload['baseurl'], $upload['default']['baseurl'], $url);
}

add_action('after_setup_theme', 'krmos_after_setup_theme', 11);
function krmos_after_setup_theme(){
    if(($width = krmso_get_option('oss_size_width')) && ($height = krmso_get_option('oss_size_height'))){
        add_theme_support('post-thumbnails');
        set_post_thumbnail_size(intval($width), intval($height), array('center', 'center'));
    }
}


add_action('admin_init', 'krmos_admin_init', 1);
function krmos_admin_init() {
    register_setting('krmos_admin_options_group', KRMOS_OPTION_KEY, array(
        'type' => 'array',
        'sanitize_callback' => 'krmos_sanitize_options',
    ));
    if(!krmso_get_option('oss')) return;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['page'], $_GET['action']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'lazyaichief-remote-media-storage-aliyun-oss') {
        krmos_admin_action();
    }
    if(krmso_get_option('oss_hd_thumbnail')) add_filter('big_image_size_threshold', '__return_false');
    add_filter('wp_privacy_exports_dir', 'krmos_privacy_exports_dir');
    add_filter('wp_privacy_exports_url', 'krmos_privacy_exports_url');
}

function krmos_sanitize_options( $input ) {
    if ( ! is_array( $input ) ) {
        return array();
    }

    $sanitized = array();

    // Sanitize checkbox values.
    $checkboxes = array(
        'oss',
        'oss_webp',
        'oss_lazyload',
        'oss_backup',
        'oss_remote',
        'oss_upload_remote',
        'oss_rename',
        'oss_url_back',
        'oss_gif',
        'oss_hd_thumbnail',
    );
    foreach ( $checkboxes as $key ) {
        $sanitized[ $key ] = isset( $input[ $key ] ) ? 1 : 0;
    }

    // Sanitize URLs.
    if ( isset( $input['oss_path'] ) ) {
        $sanitized['oss_path'] = sanitize_text_field( wp_unslash( $input['oss_path'] ) );
    }
    if ( isset( $input['oss_url'] ) ) {
        $sanitized['oss_url'] = esc_url_raw( wp_unslash( $input['oss_url'] ) );
    }
    if ( isset( $input['oss_lazyurl'] ) ) {
        $sanitized['oss_lazyurl'] = sanitize_text_field( wp_unslash( $input['oss_lazyurl'] ) );
    }

    // Sanitize text fields.
    $text_fields = array(
        'oss_akey',
        'oss_skey',
        'oss_endpoint',
        'oss_style_separator',
        'oss_fullsize_style',
        'upload_mimes',
        'oss_url_find',
        'oss_url_replace',
        'oss_remote_white',
        'oss_remote_black',
    );
    foreach ( $text_fields as $key ) {
        if ( isset( $input[ $key ] ) ) {
            $sanitized[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
        }
    }

    // Sanitize numeric fields.
    if ( isset( $input['oss_service'] ) ) {
        $sanitized['oss_service'] = absint( $input['oss_service'] );
    }
    if ( isset( $input['oss_quality'] ) ) {
        $quality                    = absint( $input['oss_quality'] );
        $sanitized['oss_quality']   = ( $quality >= 1 && $quality <= 99 ) ? $quality : 50;
    }
    if ( isset( $input['oss_size_width'] ) ) {
        $sanitized['oss_size_width'] = absint( $input['oss_size_width'] );
    }
    if ( isset( $input['oss_size_height'] ) ) {
        $sanitized['oss_size_height'] = absint( $input['oss_size_height'] );
    }

    if ( isset( $input['upload_mimes'] ) ) {
        $allowlist = krmso_get_upload_mime_allowlist();
        $items     = explode( ',', sanitize_text_field( wp_unslash( $input['upload_mimes'] ) ) );
        $safe      = array();

        foreach ( $items as $item ) {
            $kv = explode( '=', trim( $item ) );
            if ( 2 !== count( $kv ) ) {
                continue;
            }

            $ext  = strtolower( trim( $kv[0] ) );
            $mime = strtolower( trim( $kv[1] ) );

            if ( isset( $allowlist[ $ext ] ) && in_array( $mime, $allowlist[ $ext ], true ) ) {
                $safe[] = $ext . '=' . $mime;
            }
        }

        $sanitized['upload_mimes'] = implode( ',', $safe );
    }

    return $sanitized;
}

add_action('admin_menu', 'krmos_admin_menu');
function krmos_admin_menu() {
    $page_title = __('LazyAIChief Remote Media Storage with Aliyun OSS', 'lazyaichief-remote-media-storage-aliyun-oss');
    $menu_title = __('Media OSS', 'lazyaichief-remote-media-storage-aliyun-oss');
    add_options_page($page_title, $menu_title, 'manage_options', 'lazyaichief-remote-media-storage-aliyun-oss', 'krmos_options_page');
}

add_filter('views_upload', 'krmos_views_upload');
function krmos_views_upload($views){
    $link = krmos_link('options-general.php?page=lazyaichief-remote-media-storage-aliyun-oss', __('LazyAIChief Remote Media Storage with Aliyun OSS','lazyaichief-remote-media-storage-aliyun-oss'), 'button');
    if(is_super_admin()) $views['actions'] = $link;
    return $views;
}

add_filter('plugin_action_links_'.plugin_basename( __FILE__ ), 'krmos_settings_link');
function krmos_settings_link($links) {
    if(is_multisite() && (!is_main_site() || !is_super_admin())) return $links;
    $osslink = array(krmos_link('options-general.php?page=lazyaichief-remote-media-storage-aliyun-oss', __('Settings','lazyaichief-remote-media-storage-aliyun-oss')));
    return array_merge($osslink, $links);
}

add_action('update_option_' . KRMOS_OPTION_KEY, 'krmos_update_options', 10, 3);
function krmos_update_options($old, $value, $option){
    if(is_multisite() && (!is_main_site() || !is_super_admin())) return;
    update_site_option(KRMOS_OPTION_KEY, $value);
    update_option(KRMOS_OPTION_KEY, $value, false);
}

function krmos_data($key){
    $data = get_plugin_data( __FILE__ );
    return isset($data) && is_array($data) && isset($data[$key]) ? $data[$key] : '';
}

function krmos_show_more($cols, $ret=false){
    static $header = array();
    $arr  = get_user_option('managesettings_page_lazyaichief-remote-media-storage-aliyun-osscolumnshidden');
    $hide = (is_array($arr) && in_array($cols, $arr)) ? ' hidden' : '';
    $head = in_array($cols, $header) ? " class='{$cols}" : " id='{$cols}' class='manage-column";
    $out = "{$head} column-{$cols}{$hide}'";
    if(!in_array($cols, $header)) $header[] = $cols;
    if($ret) return $out;
    echo esc_attr($out);
}

add_filter('manage_settings_page_lazyaichief-remote-media-storage-aliyun-oss_columns', 'krmos_setting_columns');
function krmos_setting_columns($cols){
    $cols['_title'] = __('For Less','lazyaichief-remote-media-storage-aliyun-oss');
    $cols['oss_upload_desc'] = __('Descriptions', 'lazyaichief-remote-media-storage-aliyun-oss');
    $cols['oss_upload_example'] = __('Examples', 'lazyaichief-remote-media-storage-aliyun-oss');
    return $cols;
}

function krmos_link($url, $text='', $ext=''){
    if(empty($text)) $text = $url;
    $button = stripos($ext, 'button') !== false ? " class='button'" : "";
    $target = stripos($ext, 'blank') !== false ? " target='_blank'" : "";
    $link = "<a href='" . esc_url($url) . "'{$button}{$target}>" . wp_kses_post($text) . "</a>";
    return stripos($ext, 'p') !== false ? "<p>{$link}</p>" : "{$link} ";
}

add_action('wp_enqueue_scripts', 'krmos_enqueue', 9999);
function krmos_enqueue(){
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    if(!krmso_get_option('oss_lazyload') || empty($user_agent) || stripos($user_agent, 'MSIE') !== false) return;
    wp_enqueue_script('krmos-lazyload', plugins_url('/lib/lazyload.js', __FILE__), array('jquery'), '4.9.0', true);
}

function krmos_post_meta_boxes(){
    $screen = get_current_screen();
    if($screen->id == 'post' && method_exists($screen, 'is_block_editor') && $screen->is_block_editor()){
        add_meta_box('open_social_post_meta_class', __('LazyAIChief Remote Media Storage with Aliyun OSS', 'lazyaichief-remote-media-storage-aliyun-oss'),
            'krmos_post_action', 'post', 'side', 'default');
    }
}

function krmos_post_action(){
    $post = __('Autosave remote images to OSS', 'lazyaichief-remote-media-storage-aliyun-oss');
    wp_nonce_field('oss_upload_remote_action', 'oss_upload_remote_nonce');
    echo '<div class="misc-pub-section"><label><input name="oss_upload_remote_hidden" type="hidden" value="1" /><input name="oss_upload_remote" type="checkbox" value="1" ' . checked(krmso_get_option('oss_remote'), 1, false) . ' /> ' . esc_html($post) . '</label></div>';
}

function krmos_post_save($content){
    global $post;
    if(empty($_POST['oss_upload_remote_hidden'])){
        if(!krmso_get_option('oss_upload_remote')) return $content;
    }else{
        if(empty($_POST['oss_upload_remote'])) return $content;
    }
    if(empty($post->ID) || !current_user_can('edit_post', $post->ID)) return $content;

    // Verify nonce
    if (!isset($_POST['oss_upload_remote_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['oss_upload_remote_nonce'])), 'oss_upload_remote_action')) {
        return $content;
    }
    $upload = wp_get_upload_dir();
    $default = substr($upload['default']['baseurl'], stripos($upload['default']['baseurl'], '//'));
    $baseurl = substr($upload['baseurl'], stripos($upload['baseurl'], '//'));
    $content = stripslashes($content);
    $white = trim(krmso_get_option('oss_remote_white'));
    $black = trim(krmso_get_option('oss_remote_black'));
    $white = krmso_get_option('oss_remote_white') ? explode(',', trim(krmso_get_option('oss_remote_white'))) : false;
    $black = krmso_get_option('oss_remote_black') ? explode(',', trim(krmso_get_option('oss_remote_black'))) : false;
    $check = preg_match_all('/<img.*?(?<=data-src|data-original|data-original-src)="(.*?)"[^>]+>/', $content, $mx);
    if($check || preg_match_all('/<img.*? src="(.*?)"[^>]+>/', $content, $mx)){
        // if ( function_exists( 'set_time_limit' ) ) {
        //     @set_time_limit( 0 );
        // }
        add_filter('http_request_args', 'krmos_request_unsafe', 11, 2);//for unsafe-image url
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
            if(!pathinfo($img, 4)) $img .= '#?'.krmos_basename($img).'.png';//for unlikely-image url
            $desc = explode('#', pathinfo($img, 8));
            try{
                //$imgid = media_sideload_image($img, $post->ID, $desc[0], 'id');//one step without rename
                $tmpfile = download_url($img);
                if(!is_wp_error($tmpfile)){
                    preg_match('/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $img, $mxx);
                    $name = krmos_rename($mxx ? wp_basename($mxx[0]) : krmos_basename($img));
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
        remove_filter('http_request_args', 'krmos_request_unsafe', 11, 2);
    }
    return $content;
}

add_filter('the_content', 'krmos_content_webp', 9999);
function krmos_content_webp($content){
    if(!krmso_get_option('oss') && krmso_get_option('oss_url_back')){//no oss
        $ossurl = trim(krmso_get_option('oss_url'), '/');
        if(empty($ossurl)) return $content;
        $upload = wp_get_upload_dir();
        $localurl = isset($upload['default']) ? $upload['default']['baseurl'] : $upload['baseurl'];
        return krmso_replace_content_url_attributes_with_map( $content, $ossurl, $localurl );
    }
    if(!krmso_get_option('oss') || krmso_get_option('oss_service',10) || (!krmos_webp() && !krmso_get_option('oss_lazyload'))) return $content;
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    if(!empty($user_agent) && preg_match('/msie|spider|bot/i', $user_agent)) return $content;
    return preg_replace_callback('/<img\b[^>]*>/i', function($matches){
        $tag = $matches[0];
        if ( ! preg_match('/\bsrc=("|\')(.*?)\1/i', $tag, $src_match) ) {
            return $tag;
        }

        $transformed = krmos_auto_webp($src_match[2], krmso_get_option('oss_lazyload'));
        $new_src = $transformed;
        $data_src = '';

        if ( false !== strpos( $transformed, '" data-src="' ) ) {
            list( $new_src, $data_src ) = explode( '" data-src="', $transformed, 2 );
        }

        $tag = preg_replace(
            '/\bsrc=("|\')(.*?)\1/i',
            'src=' . $src_match[1] . krmso_escape_content_url( $new_src ) . $src_match[1],
            $tag,
            1
        );

        if ( '' !== $data_src ) {
            if ( preg_match('/\bdata-src=("|\')(.*?)\1/i', $tag, $data_src_match) ) {
                $tag = preg_replace(
                    '/\bdata-src=("|\')(.*?)\1/i',
                    'data-src=' . $data_src_match[1] . krmso_escape_content_url( $data_src ) . $data_src_match[1],
                    $tag,
                    1
                );
            } else {
                $tag = rtrim( substr( $tag, 0, -1 ) ) . ' data-src="' . krmso_escape_content_url( $data_src ) . '">';
            }
        }

        return $tag;
    }, $content);
}

add_filter('the_content', 'krmos_url_fixer', 99999);
function krmos_url_fixer($url){
    return krmso_replace_content_url_attributes( $url );
}

add_filter('wp_generate_attachment_metadata', 'krmos_generate_metadata', 9999, 2);
function krmos_generate_metadata($data, $id){
    if(!krmso_get_option('oss')) return $data;
    if(!krmso_get_option('oss_service',10)) krmos_delete_thumbnail($id, $data);
    if(!krmso_get_option('oss_backup')){
        $backup = krmso_get_backup_upload_dir();
        $file = get_attached_file($id, 1);//unfilter
        $upload = wp_get_upload_dir();
        $local = $backup ? str_replace($upload['basedir'], $backup['basedir'], $file) : false;
        if(@file_exists($local)) @wp_delete_file($local);
    }
    return $data;
}

add_filter('wp_get_attachment_metadata', 'krmos_attachment_metadata', 9999, 2);
function krmos_attachment_metadata($data, $id){
    if(!krmso_get_option('oss') || empty($data['sizes'])) return $data;
    $service = krmso_get_option('oss_service');
    if($service == 10) return $data;
    if($service == 2 || (krmso_get_option('oss_lazyload') && !is_admin())) $data['sizes'] = array();
    $ouss = krmso_get_option('oss_style_separator') ? trim(krmso_get_option('oss_style_separator')) : '?x-oss-process=style%2F';
    $ext = wp_check_filetype(krmos_basename($data['file']));
    $gif = $ext && $ext['ext'] == 'gif' ? 1 : 0;
    $quality = krmso_get_option('oss_quality') ? intval(krmso_get_option('oss_quality')) : '50';
    $quality = $gif ? '' : '%2Fquality,q_'.$quality;
    foreach ($data['sizes'] as $k => $v){
        if(!isset($v['file'])) continue;
        if($gif && $service && krmso_get_option('oss_gif')) continue;
        $postfix = $service ? "{$ouss}{$k}" : "?x-oss-process=image{$quality}%2Fresize,m_fill,w_{$v['width']},h_{$v['height']}";
        $data['sizes'][$k]['file'] = krmos_basename($data['file']).$postfix;
    }
    return $data;
}

add_filter('wp_calculate_image_srcset', 'krmos_image_srcset', 9999, 5);
function krmos_image_srcset($sources, $size, $image_src, $meta, $id){//wp_get_attachment_image_srcset
    if(!krmso_get_option('oss') || empty($meta['sizes']) || empty($sources)) return $sources;
    $upload = wp_get_upload_dir();
    if(wp_parse_url(admin_url(), PHP_URL_SCHEME) == 'https'){
        $upload['default']['baseurl'] = set_url_scheme($upload['default']['baseurl'], 'https');
    }
    foreach ($sources as $k => $v){
        $url = str_replace($upload['default']['baseurl'], $upload['baseurl'], $sources[$k]['url']);
        $url = krmos_url_fixer($url);
        if(krmos_basename($meta['file']) == wp_basename($url)){//original
            if(krmso_get_option('oss_service',1) || krmso_get_option('oss_fullsize_style')){//style
                $ouss = krmso_get_option('oss_style_separator') ? trim(krmso_get_option('oss_style_separator')) : '?x-oss-process=style%2F';
                $full = krmso_get_option('oss_fullsize_style') ? trim(krmso_get_option('oss_fullsize_style')) : 'full';
                $url .= $ouss.$full;
            }
        }
        $sources[$k]['url'] = krmos_auto_webp($url);
    }
    return $sources;
}

add_filter('intermediate_image_sizes', 'krmos_intermediate_sizes', 999);
function krmos_intermediate_sizes($sizes){
    if(!krmso_get_option('oss_hd_thumbnail')) return $sizes;
    return array_merge(array_diff($sizes, array('1536x1536', '2048x2048')));
}

add_filter('intermediate_image_sizes_advanced', 'krmos_intermediate_sizes_advanced', 999);
function krmos_intermediate_sizes_advanced($sizes){
    if(!krmso_get_option('oss_hd_thumbnail')) return $sizes;
    unset($sizes['1536x1536']);
    unset($sizes['2048x2048']);
    return $sizes;
}

add_filter('wp_get_attachment_url', 'krmos_attachment_url', 9999, 2);
function krmos_attachment_url($url, $id){
    if(!krmso_get_option('oss') || !krmso_get_option('oss_url') || !krmos_check_handle()) return $url;
    $upload = wp_get_upload_dir();
    $find = $upload['default']['baseurl'];
    $replace = $upload['baseurl'];
    $url = krmos_url_fixer(str_replace($find, $replace, $url));
    if(krmso_get_option('oss_service',10)) return $url;
    $ext = wp_check_filetype(krmos_basename($url));
    if(!$ext || !in_array($ext['ext'], array('bmp','gif','png','jpg','jpe','jpeg'))) return $url;
    if($ext && $ext['ext'] == 'gif' && krmso_get_option('oss_gif')) return $url;
    if(krmso_get_option('oss_service',1) || krmso_get_option('oss_fullsize_style')){//style
        $ouss = krmso_get_option('oss_style_separator') ? trim(krmso_get_option('oss_style_separator')) : '?x-oss-process=style%2F';
        $full = krmso_get_option('oss_fullsize_style') ? trim(krmso_get_option('oss_fullsize_style')) : 'full';
        $url .= $ouss.$full;
    }
    if(!is_admin()) $url = krmos_auto_webp($url);
    return $url;
}

add_filter('get_attached_file', 'krmos_attached_file', 9999, 2);
function krmos_attached_file($file, $id){
    if(krmso_get_option('oss') && krmso_get_option('oss_path') && krmos_check_handle()){
        $upload = wp_get_upload_dir();
        $find = $upload['default']['basedir'];
        $replace = $upload['basedir'];
        $file = str_replace($find, $replace, $file);
    }
    return $file;
}

function krmos_auto_webp($img, $lazyload=false){
    if(!krmso_get_option('oss') || krmso_get_option('oss_service',1) || krmso_get_option('oss_service',10)) return $img;
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
        if($lazyurl = krmso_get_option('oss_lazyurl')){
            $lazy = str_replace('{IMG}', $lazy, $lazyurl);
        }else{
            $lazy .= '?x-oss-process=image%2Fquality,q_10%2Fresize,m_lfit,w_20';
            if(krmos_webp()) $lazy .= '%2Fformat,webp';
        }
    }
    if(krmos_webp() && !empty($ouis) && !stripos($img, $ouis)) $img .= $ouis;
    return empty($lazy) ? $img : $lazy.'" data-src="'.$img;
}

function krmos_delete_thumbnail($id, $data=array()){
    $arr = array();
    $upload = wp_get_upload_dir();
    if(empty($data)) $data = wp_get_attachment_metadata($id, 1);//unfilter
    if(empty($data) || empty($data['sizes'])) return $arr;
    foreach ($data['sizes'] as $k => $v){
        if(empty($v['file'])) continue;
        if(krmos_basename($data['file']) == krmos_basename($v['file'])) continue;
        $file = $upload['basedir'].'/'.dirname($data['file']).'/'.krmos_basename($v['file']);
        if(@file_exists($file) && wp_delete_file($file)) $arr[] = $file;
        if(!empty($upload['default'])){
            $file = $upload['default']['basedir'].'/'.dirname($data['file']).'/'.krmos_basename($v['file']);
            if(@file_exists($file) && wp_delete_file($file)) $arr[] = $file;
        }
    }
    return $arr;
}

add_action('delete_attachment', 'krmos_delete_attachment');
function krmos_delete_attachment($id){
    if(!krmso_get_option('oss') || !krmos_check_handle()) return;
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
                $arr[] = $upload['basedir'].'/'.$subdir.'/'.krmos_basename($v['file']);
                $arr[] = $upload['default']['basedir'].'/'.$subdir.'/'.krmos_basename($v['file']);
            }
        }
    }
    if(!empty($arr)) $arr = array_unique($arr);
    foreach ($arr as $k) { if(@file_exists($k)) wp_delete_file($k); }
    krmos_delete_thumbnail($id);
}

add_filter('wp_image_editors', 'krmos_image_editors');
function krmos_image_editors($arr){//WP_Image_Editor_Imagick might have problem with Stream
    return krmso_get_option('oss') ? array('WP_Image_Editor_GD', 'WP_Image_Editor_Imagick') : $arr;
}

add_filter('fallback_intermediate_image_sizes', 'krmos_intermediate_noimage', 10, 2);
function krmos_intermediate_noimage($sizes, $metadata){//non-image
    return krmso_get_option('oss') ? array(): $sizes;
}

add_filter('upload_mimes', 'krmos_upload_mimes', 99);
function krmos_upload_mimes($mimes){
    $allowlist = krmso_get_upload_mime_allowlist();
    if($arr = trim(krmso_get_option('upload_mimes'))){
        $arr = explode(',', $arr);
        foreach($arr as $k){
            $kv = explode('=', trim($k));
            if(count($kv) !== 2) {
                continue;
            }

            $ext  = strtolower( trim( $kv[0] ) );
            $mime = strtolower( trim( $kv[1] ) );

            if ( isset( $allowlist[ $ext ] ) && in_array( $mime, $allowlist[ $ext ], true ) ) {
                $mimes[ $ext ] = $mime;
            }
        }
    }
    return $mimes;
}

add_action('current_screen', 'krmos_setting_screen');
function krmos_setting_screen() {
    $screen = get_current_screen();
    if($screen->id != 'settings_page_lazyaichief-remote-media-storage-aliyun-oss' || !krmso_get_option('oss')) return;
    $help_content = '<p>'.krmos_data('Description').'</p><br/><p>'.
        krmos_link('//promotion.aliyun.com/ntms/yunparter/invite.html?userCode=9ufcuiuf&utm_source=9ufcuiuf', __('Aliyun Coupon <span>NEW</span>', 'lazyaichief-remote-media-storage-aliyun-oss'), 'button,blank').
        krmos_link('//promotion.aliyun.com/ntms/act/oss-discount.html?userCode=9ufcuiuf&utm_source=9ufcuiuf', __('OSS Discount <span>HOT</span>', 'lazyaichief-remote-media-storage-aliyun-oss'), 'button,blank').
        krmos_link('//wordpress.org/plugins/oss-upload/', __('Rating Stars', 'lazyaichief-remote-media-storage-aliyun-oss'), 'button,blank').
        krmos_link(krmos_data('PluginURI'), __('Support and Help', 'lazyaichief-remote-media-storage-aliyun-oss'), 'button,blank').
        krmos_link('//www.imkarry.com/about', __('About Developer', 'lazyaichief-remote-media-storage-aliyun-oss'), 'button,blank');
    $help_sidebar = '<p><strong>'.__('About', 'lazyaichief-remote-media-storage-aliyun-oss').'</strong></p>'.
        krmos_link('//oss.console.aliyun.com/index', __('Aliyun OSS', 'lazyaichief-remote-media-storage-aliyun-oss'), 'p,blank').
        krmos_link('//help.aliyun.com/document_detail/32174.html', __('OSS PHP SDK', 'lazyaichief-remote-media-storage-aliyun-oss'), 'p,blank');
    $screen->add_help_tab(array('id'=>'oss_upload_help', 'title'=>__('For More', 'lazyaichief-remote-media-storage-aliyun-oss'), 'content'=>$help_content));
    $screen->set_help_sidebar($help_sidebar);
}

add_action('admin_enqueue_scripts', 'krmos_admin_enqueue');
function krmos_admin_enqueue($hook) {
    // Only load on our settings page
    if ($hook != 'settings_page_lazyaichief-remote-media-storage-aliyun-oss') {
        return;
    }

    wp_enqueue_script('krmos-admin-settings', plugins_url('/lib/admin-settings.js', __FILE__), array('jquery'), '4.9.0', true);

    // Localize script with confirmation messages
    wp_localize_script('krmos-admin-settings', 'krmosAdminL10n', array(
        'confirmClean' => __('This action would clean all thumbnails including local and OSS that filename like photo-800x600.png, cannot be undone, comfirm to process?', 'lazyaichief-remote-media-storage-aliyun-oss'),
        'confirmUpload' => __('This action would upload local storage directory to OSS, override if file exists, might take several minutes, comfirm to process?', 'lazyaichief-remote-media-storage-aliyun-oss'),
        'confirmSync' => __('This action would upload attachment from local storage that missing in OSS, might take several minutes, comfirm to process?', 'lazyaichief-remote-media-storage-aliyun-oss'),
        'confirmReset' => __('This action would regenerate metadata of all attachment in OSS, might take several minutes, comfirm to process?', 'lazyaichief-remote-media-storage-aliyun-oss'),
        'nonceClean' => wp_create_nonce('oss_upload_action_clean'),
        'nonceUpload' => wp_create_nonce('oss_upload_action_upload'),
        'nonceSync' => wp_create_nonce('oss_upload_action_sync'),
        'nonceReset' => wp_create_nonce('oss_upload_action_reset'),
    ));

    // Add inline style for metabox-prefs span
    wp_add_inline_style('wp-admin', '.metabox-prefs span {display: inline-block; vertical-align: text-bottom; margin: 1px 0 0 2px; padding: 0 5px; border-radius: 5px; background-color: #ca4a1f; color: #fff; font-size: 10px; line-height: 17px;}');
}

add_action('admin_notices', 'krmos_admin_note');
function krmos_admin_note(){
    $screen = get_current_screen();
    if($screen->id != 'settings_page_lazyaichief-remote-media-storage-aliyun-oss' || !krmso_get_option('oss') || !is_super_admin()) return;
    if(isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'test'){
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'oss_upload_test' ) ) {
             return; // Silent fail or message
        }
        $out = '';
        try{
            $rnd = md5(time());
            $file = krmso_get_option('oss_path').'/oss_upload_'.$rnd.'.txt';
            $try = file_put_contents($file, $rnd);
            if($try == strlen($rnd)){
                $out = __('Write OK, ','lazyaichief-remote-media-storage-aliyun-oss');
                $try = file_get_contents($file);
                if($try == $rnd){
                    $out .= __('Read OK, ', 'lazyaichief-remote-media-storage-aliyun-oss');
                    $try = wp_delete_file($file);
                    if($try === true){
                        $out .= __('Delete OK', 'lazyaichief-remote-media-storage-aliyun-oss');
                        $ok = true;
                    }else{
                        throw new Exception($out . __('Delete Error: ', 'lazyaichief-remote-media-storage-aliyun-oss') . $try);
                    }
                }else{
                    throw new Exception($out . __('Read Error: ', 'lazyaichief-remote-media-storage-aliyun-oss') . $try);
                }
            }else{
                throw new Exception($out . __('Write Error: ', 'lazyaichief-remote-media-storage-aliyun-oss') . $try);
            }
        }catch(Exception $ex){
            $out = esc_html($ex->getMessage());
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

function krmos_admin_action(){
    $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
    if(empty($action) || !is_super_admin()) return;

    // Add nonce verification
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'oss_upload_action_' . $action)) {
        wp_die(esc_html__('Security check failed', 'lazyaichief-remote-media-storage-aliyun-oss'));
    }

    // if ( function_exists( 'set_time_limit' ) ) {
    //     @set_time_limit( 0 );
    // }
    ob_end_clean();
    echo esc_html(str_pad('',1024));
    echo '<title>' . esc_html__('LazyAIChief Remote Media Storage with Aliyun OSS','lazyaichief-remote-media-storage-aliyun-oss') . '</title>';
    echo "<h1>" . esc_html__('Starting...', 'lazyaichief-remote-media-storage-aliyun-oss') . "</h1>\n";
    flush();
    $index = 1;
    $upload = wp_get_upload_dir();
    if($action == 'clean'){
        try{
            $files = get_posts(array('post_type'=>'attachment', 'posts_per_page'=>-1));
            $postfix = __('deleted', 'lazyaichief-remote-media-storage-aliyun-oss');
            $paths = array();
            foreach ($files as $file){
                $path = pathinfo(get_attached_file($file->ID), 1);
                if(!in_array($path, $paths)) $paths[] = $path;
                if(isset($_GET['force'])){
                    $path = pathinfo(get_attached_file($file->ID, 1), 1);
                    if(!in_array($path, $paths)) $paths[] = $path;
                }
                if($arr = krmos_delete_thumbnail($file->ID)){
                    foreach ($arr as $v){
                        echo esc_html($index++) . ". " . esc_html($v) . " " . esc_html($postfix) . "<br/>\n";
                        flush();
                    }
                }
            }
            foreach ($paths as $path){
                if(empty($path)) continue;
                $imgs = krmos_readdir($path);
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
                echo esc_html__('No thumbnail found','lazyaichief-remote-media-storage-aliyun-oss');
            }else{
                echo '<br/><hr/>';
                echo esc_html__('Clean thumbnails done','lazyaichief-remote-media-storage-aliyun-oss');
            }
        }catch(Exception $ex){
            echo esc_html($ex->getMessage());
        }
    }else if($action == 'upload'){
        $basedir = explode('/', substr($upload['basedir'].'/', 6), 2);
        try{
            $ossw = new KRMOS_OSS_Adapter;
            $ossw->create_mtu_object_by_dir($basedir[0], $upload['default']['basedir'], true);
            echo '<br/><hr/>';
            echo esc_html__('Upload local storage to OSS done', 'lazyaichief-remote-media-storage-aliyun-oss');
        }catch(Exception $ex){
            echo esc_html($ex->getMessage());
        }
    }else if($action == 'sync'){
        $files = get_posts(array('post_type'=>'attachment', 'posts_per_page'=>-1));
        $postfix = __('synced', 'lazyaichief-remote-media-storage-aliyun-oss');
        foreach ($files as $file){
            $oss = get_attached_file($file->ID);
            $local = str_replace($upload['basedir'], $upload['default']['basedir'], $oss);
            if(@file_exists($local) && !@file_exists($oss) && ($done = krmos_handler($local))){
                echo esc_html($index++) . ". " . esc_html($done) . " " . esc_html($postfix) . "<br/>\n";
                flush();
            }
        }
        if($index == 1){
            echo esc_html__('No attachments need to be synced','lazyaichief-remote-media-storage-aliyun-oss');
        }else{
            echo '<br/><hr/>';
            echo esc_html__('Sync missing attachments to OSS done','lazyaichief-remote-media-storage-aliyun-oss');
        }
    }else if($action == 'reset'){
        // Only increase memory if absolutely necessary and not globally
        if ( function_exists( 'ini_set' ) ) {
            // @ini_set( 'memory_limit', '2048M' );
        }
        $files = get_posts(array('post_type'=>'attachment', 'posts_per_page'=>-1));
        $postfix = __('reset', 'lazyaichief-remote-media-storage-aliyun-oss');
        foreach ($files as $file){
            if(!wp_attachment_is_image($file->ID)) continue;
            $img = get_attached_file($file->ID);
            $metadata = wp_generate_attachment_metadata($file->ID, $img);
            wp_update_attachment_metadata($file->ID, $metadata);
            echo esc_html($index++) . ". " . esc_html($file->ID) . " " . esc_html($img) . " " . esc_html($postfix) . "<br/>\n";
            flush();
        }
        echo '<br/><hr/>';
        echo esc_html__('Reset attachments metadata done','lazyaichief-remote-media-storage-aliyun-oss');
    }
    flush();
    exit();
}

function krmos_options_page(){
    $upload = wp_get_upload_dir();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LazyAIChief Remote Media Storage with Aliyun OSS','lazyaichief-remote-media-storage-aliyun-oss')?>
            <a class="page-title-action" href="<?php echo esc_url(krmos_data('PluginURI'));?>" target="_blank"><?php echo esc_html(krmos_data('Version'));?></a>
        </h1>
        <form action="options.php" method="post">
        <?php settings_fields('krmos_admin_options_group'); ?>
        <table class="form-table">
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Enable','lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss]" type="checkbox" value="1" <?php checked(krmso_get_option('oss'),1);?> />
            <?php esc_html_e('Use OSS as media library storage','lazyaichief-remote-media-storage-aliyun-oss')?></label>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Access Key','lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_akey]" size="60" placeholder="Access Key" value="<?php echo esc_attr(krmso_get_option('oss_akey'))?>" required />
            <?php echo wp_kses_post(krmos_link('//ram.console.aliyun.com/manage/ak', '?', 'blank')); ?>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Secret Key','lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <input type="password" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_skey]" size="60" placeholder="Secret Key" value="<?php echo esc_attr(krmso_get_option('oss_skey'))?>" required />
            <?php echo wp_kses_post(krmos_link('//ram.console.aliyun.com/manage/ak', '?', 'blank')); ?>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Upload Path','lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <input type="url" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_path]" size="60" placeholder="oss://{BUCKET}/{PATH}" value="<?php echo esc_attr(rtrim(krmso_get_option('oss_path'), '/'));?>" required />
            <?php echo wp_kses_post(krmos_link('//help.aliyun.com/document_detail/31902.html', '?', 'blank')); ?>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('<code>{BUCKET}</code> is Bucket name, <code>{PATH}</code> can be empty, with no slash at the end','lazyaichief-remote-media-storage-aliyun-oss')?></small></p>
            <div <?php krmos_show_more('oss_upload_example'); ?>>
            <p><small><code>oss://my-bucket</code></small></p>
            <p><small><code>oss://my-bucket/uploads</code></small></p>
            </div>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Visit URL','lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <input type="url" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_url]" size="60" placeholder="http://oss.aliyuncs.com/{BUCKET}/{PATH}" value="<?php echo esc_attr(rtrim(krmso_get_option('oss_url'), '/'));?>" required />
            <?php echo wp_kses_post(krmos_link('//help.aliyun.com/document_detail/31902.html', '?', 'blank')); ?>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('<code>{BUCKET}</code> can be directory or domain, <code>{PATH}</code> can be empty','lazyaichief-remote-media-storage-aliyun-oss')?></small></p>
            <div <?php krmos_show_more('oss_upload_example'); ?>>
            <p><small><code>http://my-bucket.oss-cn-shenzhen.aliyuncs.com</code></small></p>
            <p><small><code>http://my-bucket.oss-cn-shenzhen.aliyuncs.com/uploads</code></small></p>
            <p><small><code>http://www.my-oss-domain.com</code></small></p>
            <p><small><code>https://www.my-oss-domain.com/uploads</code></small></p>
            <p><small><code>https://img.my-oss-domain.com</code></small></p>
            </div>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Upload EndPoint','lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_endpoint]" size="60" placeholder="oss-cn-hangzhou.aliyuncs.com" value="<?php echo esc_attr(krmso_get_option('oss_endpoint'))?>" required />
            <?php echo wp_kses_post(krmos_link('//help.aliyun.com/document_detail/31837.html', '?', 'blank')); ?>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Endpoint of your Bucket, can be internal address if WEB SERVER is in the same area with OSS','lazyaichief-remote-media-storage-aliyun-oss')?></small></p>
            <div <?php krmos_show_more('oss_upload_example'); ?>>
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
            if(krmso_get_option('oss') && krmso_get_option('oss_akey') && krmso_get_option('oss_skey') && krmso_get_option('oss_endpoint')){
                $test_nonce = wp_create_nonce('oss_upload_test');
                echo wp_kses_post(krmos_link('options-general.php?page=lazyaichief-remote-media-storage-aliyun-oss&settings-updated=test&_wpnonce=' . $test_nonce, __('Run a test', 'lazyaichief-remote-media-storage-aliyun-oss'), 'p,button'));
            } ?>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Image Thumbnails','lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_service]" type="radio" value="0" <?php checked(krmso_get_option('oss_service'),0);?> /> <?php esc_html_e('Use Image Service via Parameter, default and simple','lazyaichief-remote-media-storage-aliyun-oss')?></label>
            <?php echo wp_kses_post(krmos_link('//help.aliyun.com/document_detail/44688.html', '?', 'blank')); ?></p>
            <p <?php krmos_show_more('oss_upload_example'); ?>><small><code>photo.jpg?x-oss-process=image%2Fquality,q_<?php echo krmso_get_option('oss_quality') ? intval(krmso_get_option('oss_quality')) : '50'; ?>%2Fresize,m_fill,w_{width},h_{height}</code></small></p><br/>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_service]" type="radio" value="1" <?php checked(krmso_get_option('oss_service'),1);?> /> <?php esc_html_e('Use Image Service via Style, powerful but require styles setting on OSS','lazyaichief-remote-media-storage-aliyun-oss')?></label>
            <?php echo wp_kses_post(krmos_link('//help.aliyun.com/document_detail/44687.html', '?', 'blank')); ?></p>
            <p <?php krmos_show_more('oss_upload_example'); ?>><small><code>photo.jpg<?php echo esc_html(krmso_get_option('oss_style_separator') ? trim(krmso_get_option('oss_style_separator')) : '?x-oss-process=style%2F'); ?>{style}</code>:
            <?php foreach (get_intermediate_image_sizes() as $v){ echo '<code>'.esc_html($v).'</code> '; } ?>
            </small></p><br/>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_service]" type="radio" value="10" <?php checked(krmso_get_option('oss_service'),10);?> /> <?php esc_html_e('Use physical thumbnails, check this when having problem with theme','lazyaichief-remote-media-storage-aliyun-oss')?></label></p>
            <p <?php krmos_show_more('oss_upload_example'); ?>><small><code>photo-{width}x{height}.jpg</code></small></p><br/>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_service]" type="radio" value="2" <?php checked(krmso_get_option('oss_service'),2);?> /> <?php esc_html_e('Disable image thumbnails','lazyaichief-remote-media-storage-aliyun-oss')?></label></p>
            <p <?php krmos_show_more('oss_upload_example'); ?>><small><code>photo.jpg</code></small></p><br/>
            <p><?php
                echo wp_kses_post(krmos_link('options-media.php', __('Media Sizes Options', 'lazyaichief-remote-media-storage-aliyun-oss'), 'button'));
                echo wp_kses_post(krmos_link('?page=lazyaichief-remote-media-storage-aliyun-oss&action=clean', __('Clean Thumbnails', 'lazyaichief-remote-media-storage-aliyun-oss'), 'button,blank'));
                if(!krmso_get_option('oss_service',2)) echo wp_kses_post(krmos_link('?page=lazyaichief-remote-media-storage-aliyun-oss&action=reset', __('Regenerate Thumbnails', 'lazyaichief-remote-media-storage-aliyun-oss'), 'button,blank'));
            ?></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Thumbnail Quality', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input type="number" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_quality]" size="10" min="1" max="99" placeholder="15" value="<?php echo esc_attr(krmso_get_option('oss_quality'))?>" /></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Set the quality of thumbnail for OSS Image Servie to speed up image loading, the smaller the faster', 'lazyaichief-remote-media-storage-aliyun-oss');?>: <code>1 ~ 99</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Featured Image', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label>
                <input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_size_width]" size="10" value="<?php echo esc_attr(krmso_get_option('oss_size_width'))?>" /> x
                <input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_size_height]" size="10" value="<?php echo esc_attr(krmso_get_option('oss_size_height'))?>" />
            </label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Set the featured image dimensions when thumbnails enabled (width x height)', 'lazyaichief-remote-media-storage-aliyun-oss');?>: <code>800</code> x <code>450</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('HD Thumbnails', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_hd_thumbnail]" type="checkbox" value="1" <?php checked(krmso_get_option('oss_hd_thumbnail'),1);?> />
            <?php esc_html_e('Disable <code>1356x1356</code>,<code>2048x2048</code> sizes when generate thumbnails','lazyaichief-remote-media-storage-aliyun-oss')?></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Disable the whole high definition resolution things come with WordPress 5.3 like <code>image-scaled.png</code>', 'lazyaichief-remote-media-storage-aliyun-oss');?></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Style Separator', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_style_separator]" size="60" value="<?php echo esc_attr(krmso_get_option('oss_style_separator'))?>" /> <?php echo wp_kses_post(krmos_link('//help.aliyun.com/document_detail/48884.html', '?', 'blank')); ?></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Custom style separator for OSS Image Service style','lazyaichief-remote-media-storage-aliyun-oss')?>: <code>?x-oss-process=style%2F</code> <code>-</code> <code>_</code> <code>!</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Fullsize Style', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_fullsize_style]" size="60" value="<?php echo esc_attr(krmso_get_option('oss_fullsize_style'))?>" />
            <?php echo wp_kses_post(krmos_link('//help.aliyun.com/document_detail/44686.html', '?', 'blank')); ?></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Default full size image style for OSS Image Service','lazyaichief-remote-media-storage-aliyun-oss')?>: <code>full</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('GIF Style', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_gif]" type="checkbox" value="1" <?php checked(krmso_get_option('oss_gif'),1);?> />
            <?php esc_html_e('Using special OSS Image Service style for <code>GIF</code> format','lazyaichief-remote-media-storage-aliyun-oss')?> <?php echo wp_kses_post(krmos_link('//help.aliyun.com/document_detail/44957.html', '?', 'blank')); ?></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Check this to skip style for GIF image if having no animation effect','lazyaichief-remote-media-storage-aliyun-oss')?>
            </small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Auto Compress', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_webp]" type="checkbox" value="1" <?php checked(krmso_get_option('oss_webp'),1);?> />
            <?php esc_html_e('Compress as <code>WebP</code> format automatically if browser support','lazyaichief-remote-media-storage-aliyun-oss')?> <?php echo wp_kses_post(krmos_link('//help.aliyun.com/document_detail/44703.html', '?', 'blank')); ?></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Choose webp format on OSS if using styles for Image Service','lazyaichief-remote-media-storage-aliyun-oss')?></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Lazyload', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_lazyload]" type="checkbox" value="1" <?php checked(krmso_get_option('oss_lazyload'),1);?> />
            <?php esc_html_e('Delay loading of images in long web pages','lazyaichief-remote-media-storage-aliyun-oss')?>
            <?php echo wp_kses_post(krmos_link('//plugins.jquery.com/lazyload/', '?', 'blank')); ?></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Images outside of viewport wont be loaded before user scrolls to them','lazyaichief-remote-media-storage-aliyun-oss')?></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Lazyload URL', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_lazyurl]" size="60" value="<?php echo esc_attr(krmso_get_option('oss_lazyurl'))?>" /></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Default image url for lazyload, could be with Image Service suffix, or base64 data, or normal url. <code>{IMG}</code> means original','lazyaichief-remote-media-storage-aliyun-oss')?></small></p>
            <div <?php krmos_show_more('oss_upload_example'); ?>>
            <p><small><code>{IMG}?x-oss-process=image%2Fquality,q_10%2Fresize,m_lfit,w_20</code></small></p>
            <p><small><code>{IMG}<?php echo esc_html(krmso_get_option('oss_style_separator') ? trim(krmso_get_option('oss_style_separator')) : '?x-oss-process=style%2F'); ?>lazyload-style</code></small></p>
            <p><small><code>data:image/gif;base64,R0lGODdhAQABAPAAAMPDwwAAACwAAAAAAQABAAACAkQBADs=</code></small></p>
            <p><small><code>//img.domain.com/xxx/lazyload.png</code></small></p>
            </div>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Upload Mimes', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[upload_mimes]" size="60" value="<?php echo esc_attr(krmso_get_option('upload_mimes'))?>" />
                <?php echo wp_kses_post(krmos_link('//codex.wordpress.org/Function_Reference/get_allowed_mime_types', '?', 'blank')); ?></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Add safe file extensions and mime types to the allowed upload list','lazyaichief-remote-media-storage-aliyun-oss')?>: <code>flac=audio/x-flac,webm=video/webm</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Auto Rename', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_rename]" type="checkbox" value="1" <?php checked(krmso_get_option('oss_rename'),1);?> />
            <?php esc_html_e('Auto rename uploaded file if having like Non-ASCII problem','lazyaichief-remote-media-storage-aliyun-oss')?></label></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('URL Fixer', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_url_back]" type="checkbox" value="1" <?php checked(krmso_get_option('oss_url_back'),1);?> />
            <?php esc_html_e('Auto relocate attachments in past posts when OSS disabled','lazyaichief-remote-media-storage-aliyun-oss')?></label></p><br/>
            <p><label><input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_url_find]" size="60" value="<?php echo esc_attr(krmso_get_option('oss_url_find'))?>" /></label></p>
            <p><label><input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_url_replace]" size="60" value="<?php echo esc_attr(krmso_get_option('oss_url_replace'))?>" /></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Find and replace whatever strings you want to fix the attachment url','lazyaichief-remote-media-storage-aliyun-oss')?>: <code>http,upload</code> <code>https,uploads</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Remote Image', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_remote]" type="checkbox" value="1" <?php checked(krmso_get_option('oss_remote'),1);?> />
            <?php esc_html_e('Enable remote images autosave when edit post/page','lazyaichief-remote-media-storage-aliyun-oss')?></label></p><br/>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_upload_remote]" type="checkbox" value="1" <?php checked(krmso_get_option('oss_upload_remote'),1);?> />
            <?php esc_html_e('Enable remote images autosave when import post/page','lazyaichief-remote-media-storage-aliyun-oss')?></label></p><br/>
            <p><label><input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_remote_white]" size="60" value="<?php echo esc_attr(krmso_get_option('oss_remote_white'))?>" /></label></p>
            <p><label><input type="text" name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_remote_black]" size="60" value="<?php echo esc_attr(krmso_get_option('oss_remote_black'))?>" /></label></p>
            <p <?php krmos_show_more('oss_upload_desc'); ?>><small><?php esc_html_e('Whitelist / Blacklist rules for remote images autosave','lazyaichief-remote-media-storage-aliyun-oss')?>: <code>jianshu.io</code> <code>noimg.com,icon.com</code></small></p>
        </td></tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Local Backup', 'lazyaichief-remote-media-storage-aliyun-oss')?></th>
        <td>
            <p><label><input name="<?php echo esc_attr( KRMOS_OPTION_KEY ); ?>[oss_backup]" type="checkbox" value="1" <?php checked(krmso_get_option('oss_backup'),1);?> />
            <?php esc_html_e('Backup original image to local storage','lazyaichief-remote-media-storage-aliyun-oss')?> <small><code>
            <?php
                $backup = krmso_get_backup_upload_dir();
                echo esc_html($backup ? $backup['path'] : '');
            ?>
            </code></small></label></p><br />
            <?php
                echo wp_kses_post(krmos_link('?page=lazyaichief-remote-media-storage-aliyun-oss&action=sync', __('Upload Missing Attachment', 'lazyaichief-remote-media-storage-aliyun-oss'), 'button,blank'));
                echo wp_kses_post(krmos_link('?page=lazyaichief-remote-media-storage-aliyun-oss&action=upload', __('Upload Whole Local Storage', 'lazyaichief-remote-media-storage-aliyun-oss'), 'button,blank'));
            ?>
        </td></tr>
        </table>
        <?php submit_button();?>
    </div>
    <?php
}

?>
