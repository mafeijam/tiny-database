<?php

class DB
{
	public static function setup($setting)
	{
		return Database::run($setting);
	}

	public static function __callStatic($fn, $args)
	{
		$obj = Database::run();

		switch (count($args)) {
			case 0:
				return $obj->$fn();
				break;
			case 1:
				return $obj->$fn($args[0]);
				break;
			case 2:
				return $obj->$fn($args[0], $args[1]);
				break;
			case 3:
				return $obj->$fn($args[0], $args[1], $args[2]);
				break;
		}
	}
}