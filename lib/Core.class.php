<?php
/**
 * Core-class. Provides basic module handling
 *
 * @author	Tim Düsterhus
 * @copyright	2010 - 2011 Tim Düsterhus
 */
class Core {
	
	/**
	 * Singleton-Instance
	 *
	 * @var	Core
	 */
	private static $instance = null;
	
	/**
	 * Main-Config
	 *
	 * @var	Config
	 */
	private static $config = null;
	
	/**
	 * Logger
	 *
	 * @var	Log
	 */
	private static $log = null;
	
	/**
	 * Bot-instance
	 *
	 * @var	Bot
	 */
	private static $bot = null;
	
	/**
	 * Holds the loaded modules
	 *
	 * @var	array<Module>
	 */
	private static $modules = array();
	
	private function __construct() {
		self::init();
		self::$log = new Log();
		self::log()->info = 'Starting, PID is '.getmypid();
		self::$config = new Config();
		self::log()->info = 'Loaded Config';
		self::$bot = new Bot();
		
		$modules = self::config()->config['modules'];
		// load default modules
		self::log()->info = 'Loading Modules';
		foreach ($modules as $module) {
			self::loadModule($module);
		}
		self::bot()->work();
	}
	
	/**
	 * Initializes folders
	 *
	 * @return	void
	 */
	protected static function init() {
		if (!file_exists(DIR.'log/')) mkdir(DIR.'log/', 0777);
		if (!file_exists(DIR.'cache/')) mkdir(DIR.'cache/', 0777);
		if (!file_exists(DIR.'config/')) mkdir(DIR.'config/', 0777);
	}
	
	/**
	 * Shuts the bot down
	 *
	 * @return	void
	 */
	public static function destruct() {
		// break in child
		if (self::$bot !== null) {
			if (!self::$bot->isParent()) return;
		}
		self::$log->info = 'Shutting down';
		
		// send leave message
		self::$bot->getConnection()->leave();
		self::$log->info = 'Left chat';
		// write the configs
		self::$config->write();
		self::$log->info = 'Written config';
		
		// call destructors of modules
		foreach (self::$modules as $module) {
			$module->destruct();
		}
		self::$log->info = 'Unloading modules';
		
		// clear class cache
		$files = glob(DIR.'cache/*.class.php');
		foreach ($files as $file) {
			unlink($file);
		}
		self::$log->info = 'Cleaned cache';
		unlink(DIR.'config/bot.pid');
	}
	
	public static function isOp($userID) {
		return isset(self::config()->config['op'][$userID]);
	}

	/**
	 * Loads the given module
	 *
	 * @var		string	$module		module-name
	 * @return	string			module-address
	 */
	public static function loadModule($module) {
		// handle loaded
		if (isset(self::$modules[$module])) return self::log()->error = 'Tried to load module '.$module.' that is already loaded';
		
		// handle wrong name
		if (!file_exists(DIR.'lib/Module'.ucfirst($module).'.class.php')) return self::log()->error = 'Tried to load module '.$module.' but there is no matching classfile';
		
		// copy to prevent classname conflicts
		$address = 'Module'.substr(StringUtil::getRandomID(), 0, 8);
		$data = str_replace('class Module'.$module.' ',  "// Module is: ".$module."\nclass ".$address.' ', file_get_contents(DIR.'lib/Module'.ucfirst($module).'.class.php'));
		file_put_contents(DIR.'cache/'.$address.'.class.php', $data);

		exec('php -l '.escapeshellarg(DIR.'cache/'.$address.'.class.php'), $error, $code);
		if ($code !== 0) return self::log()->error = 'Tried to load a module with syntax errors';

		// now load
		require_once(DIR.'cache/'.$address.'.class.php');
		self::$modules[$module] = new $address();
		
		// check whether it is really a module
		if (!self::$modules[$module] instanceof Module) {
			self::log()->error = 'Tried to load Module '.$module.' but it is no module, unloading';
			return self::unloadModule($module);
		}
		
		self::config()->config['modules'][$module] = $module;
		self::config()->write();
		
		self::log()->info = 'Loaded module '.$module.' @ '.$address;
		return $address;
	}
	
	/**
	 * Unloads the given module
	 *
	 * @var		string	$module		module-name
	 * @return	void
	 */
	public static function unloadModule($module) {
		if (!isset(self::$modules[$module])) return self::log()->error = 'Tried to unload module '.$module.' that is not loaded';
		$address = get_class(self::$modules[$module]);
		unlink(DIR.'cache/'.$address.'.class.php');
		
		self::$modules[$module]->destruct();
		
		unset(self::$modules[$module]);
		unset(self::config()->config['modules'][$module]);
		self::config()->write();
		
		self::log()->info = 'Unloaded module '.$module.' @ '.$address;
	}
	
	/**
	 * Reloads the given module
	 *
	 * @var		string	$module		module-name
	 * @return	void
	 */
	public static function reloadModule($module) {
		self::unloadModule($module);
		self::loadModule($module);
	}
	
	/**
	 * Checks whether the module is loaded
	 *
	 * @var		string	$module		module-name
	 * @return	boolean			module loaded
	 */
	public static function moduleLoaded($module) {
		return isset(self::$modules[$module]);
	}
	
	/**
	 * Return the loaded modules
	 * 
	 * @return	array<Module>
	 */
	public static function getModules() {
		return self::$modules;
	}
	
	/**
	 * Returns the Core-object
	 * 
	 * @return	Core		Singleton-Instance
	 */
	public final static function get() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	public static function __callStatic($name, $arguments) {
		if (isset(self::$modules[$name])) {
			return self::$modules[$name];
		}
		else if (isset(self::$$name)) {
			return self::$$name;
		}
		else {
			self::log()->error = 'Tried to access unknown member '.$name.' in Core';
		}
	}
	
	/**
	 * Logs PHP-Errors
	 *
	 * @var		int	$errorNo	error-number
	 * @var		string	$message	error-message
	 * @var		string	$filename	file with error
	 * @var		int	$lineNo		line in the file
	 * @return	void
	 */
	public static final function handleError($errorNo, $message, $filename, $lineNo) { 
		if (error_reporting() != 0) {
			$type = 'error';
			switch ($errorNo) {
				case 2: $type = 'warning';
					break;
				case 8: $type = 'notice';
					break;
			}

			self::$log->error = 'PHP '.$type.' in file '.$filename.' ('.$lineNo.'): '.$message;
		}
	}
}
