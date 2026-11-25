<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       lib/psrlog_compat.php
 * \ingroup    fintsbank
 * \brief      Keep all Psr\Log classes from one package version
 *
 * Dolibarr itself ships psr/log 1.x (htdocs/includes/Psr/Log, includes/sabre/psr/log), this module
 * ships psr/log 3.x in vendor/. If Dolibarr or another module has already loaded
 * Psr\Log\LoggerInterface 1.x, the 3.x NullLogger/AbstractLogger from vendor/ do not match its
 * signatures: "Declaration of Psr\Log\NullLogger::log(...) must be compatible with
 * Psr\Log\LoggerInterface::log(...)". This autoloader runs before Composer and loads the missing
 * Psr\Log classes from the same directory as the interface that is already in memory.
 * Include it after vendor/autoload.php (Composer prepends its loader, this one must come first).
 * If no Psr\Log interface is loaded yet, it does nothing and Composer loads vendor/ as usual.
 */

if (!function_exists('fintsbank_psrlog_autoload')) {
	/**
	 * @param string $class Class name
	 * @return void
	 */
	function fintsbank_psrlog_autoload($class)
	{
		if (strncmp($class, 'Psr\\Log\\', 8) !== 0 || $class === 'Psr\\Log\\LoggerInterface') {
			return;
		}
		if (!interface_exists('Psr\\Log\\LoggerInterface', false)) {
			return;
		}
		$ref = new ReflectionClass('Psr\\Log\\LoggerInterface');
		$file = dirname((string) $ref->getFileName()).'/'.str_replace('\\', '/', substr($class, 8)).'.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
	spl_autoload_register('fintsbank_psrlog_autoload', true, true);
}
