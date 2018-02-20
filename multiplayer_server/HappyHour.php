<?php

class HappyHour {

	private static $active_until = 0;
	public static $random_hour = 0;

	public static function activate ($duration = 3600) {
		
		if ($pr2_server::$tournament === true) {
			return false;
		}
		
		$time = time();
		
		if (self::$active_until < $time) {
			self::$active_until = $time;
		}
		
		self::$active_until += $duration;
	}

	public static function isActive () {
		$current_hour = (int) date('G');
		return self::$active_until >= time() || $current_hour === self::$random_hour;
	}

}

if (pr2_server::$tournament === false) {
	HappyHour::$random_hour = rand(0, 36);
}

?>
