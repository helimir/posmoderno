<?php 
define('INSTALLED', true);

	/*
	 * --------------------------------------------------------------------
	 * VARIABLES DE ENTORNO
	 * --------------------------------------------------------------------
	 *
	 * Los valores de .env (raiz del proyecto) tienen prioridad sobre los
	 * valores por defecto de este archivo. Ver .env.example.
	 *
	 * Este archivo SE VERSIONA y no contiene ningun secreto: la clave de la
	 * base de datos vive unicamente en .env, que esta en .gitignore. Asi un
	 * clone nuevo arranca con solo copiar .env.example a .env.
	 */
	require_once __DIR__ . '/_inc/config/env.php';

	$sql_details = array(
'host' => env_get('DB_HOST', 'localhost'),
'db' => env_get('DB_NAME', 'pos_moderno'),
'user' => env_get('DB_USER', 'root'),
'pass' => env_get('DB_PASS', ''),
'port' => env_get('DB_PORT', '3306')
	);

	// Sin DB_PASS la conexion falla con un mensaje de MySQL poco util.
	if ($sql_details['pass'] === '' && getenv('DB_PASS') === false) {
		die('Falta DB_PASS en el archivo .env de la raiz del proyecto. Copia .env.example a .env y completalo.');
	}


	/*
	 * --------------------------------------------------------------------
	 * GLOBAL CONSTANTS
	 * --------------------------------------------------------------------
	 */
	define('FILE_READ_MODE', 0644);
	define('FILE_WRITE_MODE', 0666);
	define('DIR_READ_MODE', 0755);
	define('DIR_WRITE_MODE', 0755);
	
	define('ROOT', __DIR__);
	define('ADMINDIRNAME', 'admin');
	define('DIR_INCLUDE', ROOT.'/_inc/');
	define('DIR_LIBRARY', DIR_INCLUDE.'/lib/');
	define('DIR_MODEL', DIR_INCLUDE.'/model/');
	define('DIR_VENDOR', DIR_INCLUDE.'/vendor/');
	define('DIR_ADMIN', ROOT.'/'.ADMINDIRNAME.'/');
	define('DIR_HELPER', ROOT.'/_inc/helper/');
	define('DIR_LANGUAGE', ROOT.'/language/');
	define('DIR_STORAGE', ROOT.'/storage/');
	define('DIR_ASSET', ROOT.'/assets/');
	define('DIR_EMAIL_TEMPLATE', DIR_INCLUDE.'template/email/');
	define('DIR_BACKUP', DIR_STORAGE.'backups/');
	define('DIR_LOG', DIR_STORAGE.'logs/');


	/*
	 * --------------------------------------------------------------------
	 * OFFLINE-ONLINE SYNCHRONIZATION
	 * --------------------------------------------------------------------
	 *
	 * Toggling Offline Online Synchronization
	 *
	 */
	define('SYNCHRONIZATION', env_bool('SYNCHRONIZATION', false));
	define('SYNCSERVERURL', env_get('SYNCSERVERURL', ''));


	/*
	 * --------------------------------------------------------------------
	 * SUB-DIRECTORY
	 * --------------------------------------------------------------------
	 *
	 * This is usefull when you will host the app inside a subdirectory of root
	 *
	 * Prioridad: APP_SUBDIRECTORY > path de APP_URL > 'pos_moderno'
	 */
	$subdirectory = env_get('APP_SUBDIRECTORY', null);
	if ($subdirectory === null) {
		$app_url = env_get('APP_URL', null);
		if ($app_url !== null) {
			// http://localhost/pos_moderno -> pos_moderno
			$subdirectory = trim((string) parse_url($app_url, PHP_URL_PATH), '/');
		} else {
			$subdirectory = 'pos_moderno';
		}
	}
	define('SUBDIRECTORY', $subdirectory);


	/*
	 * --------------------------------------------------------------------
	 * FILEMANAGER PATH
	 * --------------------------------------------------------------------
	 *
	 * If you use FTP for filemanger then just left it as blank
	 */
	define('FILEMANAGERPATH', ROOT.'/storage/products/');


	/*
	 * --------------------------------------------------------------------
	 * FILEMANAGER URL
	 * --------------------------------------------------------------------
	 *
	 * If you use FTP for filemanger then just left it as blank
	 * Example: http://modernpos/storage directory
	 */
	define('FILEMANAGERURL', env_get('FILEMANAGERURL', rtrim(env_get('APP_URL', 'http://localhost/pos_moderno'), '/') . '/storage/products'));


	/*
	 * --------------------------------------------------------------------
	 * ENABLE/DISABLE HOOKING SYSTEM
	 * --------------------------------------------------------------------
	 */
	define('HOOK', env_bool('HOOK', false));


	/*
	 * --------------------------------------------------------------------
	 * ENABLE/DISABLE LOGGING SYSTEM
	 * 
	 * to work properly set HOOK as 1
	 * --------------------------------------------------------------------
	 */
	define('LOG', env_bool('LOG', false));


	/*
	 * ----------------------------------------------------------------------------
	 * SUSPEND USER ACCOUNT FOR A SPECIFIC DURATION, IF LOGIN ATTEMPTS WERE FAILED
	 *-----------------------------------------------------------------------------
	 */
	define('TOTAL_LOGIN_TRY', 10);

	/*
	 * --------------------------------------------------------------------
	 * IF ACCOUNT IS LOCKED, UNLOCK AFTER THE SPECIFIED TIME (MINUTES)
	 *---------------------------------------------------------------------
	 */
	define('UNLOCK_ACCOUNT_AFTER', 10);

	/*
	 * --------------------------------------------------------------------
	 * INSTALLMENT SELLING
	 * --------------------------------------------------------------------
	 *
	 */
	define('INSTALLMENT', env_bool('INSTALLMENT', true));


	/*
	 * --------------------------------------------------------------------
	 * ALLOWED THESE IPs ONLY, IF EMPTY THEN ALLOWES ALL IPs
	 * --------------------------------------------------------------------
	 */
	define('DENIED_IPS', array());


	/*
	 * --------------------------------------------------------------------
	 * DENIED THESE IPs TO  ACCESS THE SYSTEM
	 * --------------------------------------------------------------------
	 */
	define('ALLOWED_ONLY_IPS', array());


	/*
	 * --------------------------------------------------------------------
	 * INVOICE PREFIXES
	 * --------------------------------------------------------------------
	 */
	$invoice_init_prefix = array(
		'purchase' => 'B',
		'due_paid' => 'F',
		'expense' => 'E',
		'withdraw' => 'W',
		'deposit' => 'E',
	);

	/*
	 * --------------------------------------------------------------------
	 * ENABLE/DISABLE DEMO
	 * --------------------------------------------------------------------
	 *
	 * DEMO should always be set to false for production
	 * To restrict the instillation as demo set DEMO to true
	 */
	define('DEMO', env_bool('DEMO', false));


	/*
	 * --------------------------------------------------------------------
	 * USE COMPILED ASSETS
	 * --------------------------------------------------------------------
	 *
	 * If true then system will use compileted assets, i.e. minified/combined js, css and images
	 *
	 * Solo afecta al JS. El CSS tiene su propia bandera (USECOMPILEDCSS)
	 * porque el bundle cssmin/main.css esta desactualizado y no lo regenera
	 * el build: mix.styles() esta comentado en webpack.mix.js.
	 */
	define('USECOMPILEDASSET', env_bool('USECOMPILEDASSET', true));

	/*
	 * --------------------------------------------------------------------
	 * USE COMPILED CSS
	 * --------------------------------------------------------------------
	 *
	 * Si es true se sirve cssmin/main.css (bundle de 413 KB). Si es false
	 * se sirven los CSS desde el codigo fuente, que es lo que hay que usar
	 * para poder modificar el aspecto sin recompilar: el bundle no lo
	 * regenera nadie y arrastra una version antigua de theme.css.
	 */
	define('USECOMPILEDCSS', env_bool('USECOMPILEDCSS', false));

	/*
	 * --------------------------------------------------------------------
	 * Right to Left (RTL) Toggling
	 * --------------------------------------------------------------------
	 *
	 * If true then system will use compileted assets, i.e. minified/combined js, css and images
	 */
	define('RTL', env_bool('RTL', false));