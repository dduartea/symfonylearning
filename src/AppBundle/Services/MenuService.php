<?php

/**
 * File containing the ContentService class.
 *
 * (c) www.aplyca.com
 * (c) 
 */
namespace AppBundle\Services;

/**
 * Helper class for getting ccb content easily.
 */
class MenuService {
	private $menu;
	private $logger;
	public function __construct($logger, $menu) {
		$this->logger = $logger;
		$this->menu = $menu;
	}
	/*
	 *
	 */
	public function getMenuService() {
		$menu = $this->menu;
		
		//$menu = false;
		//$menu = "connection-failed";
		
		if (empty ( $menu )) {
			$this->logger->warning ( "Menu not found" );
			
			throw new \UnexpectedValueException ( "Menu not found" );
		}
		
		if ($menu == "connection-failed") {
			$this->logger->critical ( "Error: Connection failed" );
			
			throw new \Exception ( "Connection failed" );
		}
		return $menu;
	}
}
