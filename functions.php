<?php

function db($table = null)
{
	return isset($table) ? DB::table($table) : DB::run();
}

function table($name)
{
	return db($name);
}

function d()
{
	echo '<pre>';
	call_user_func_array('var_dump', func_get_args());
}

function dd()
{
	d();
	exit;
}

function pr($var, $hr = true)
{
	echo '<pre>';
	print_r($var);
	echo '</pre>';
	if ($hr) hr();
}

function pl($var)
{
	echo "<p>$var</p>";
}

function hr()
{
	echo '<hr>';
}