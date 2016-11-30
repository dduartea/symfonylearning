<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
// paquete necesario para hacer un response
class BlogController extends Controller {
	public function listAction() {
		return new Response ( '<html><body>Hello word!!</body></html>' );
	}
	
	/**
	 *
	 * para probar una variable enrutada por el routing.yml
	 *
	 * @param unknown $slug        	
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function homeAction($slug) {
		return $this->render ( 'blog/home.html.twig', array (
				'number' => $slug 
		) );
	}
	
	/**
	 *
	 * para probar una variable enrutada por el routing.yml
	 *
	 * @param unknown $slug        	
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function contactAction($slug) {
		return $this->render ( 'blog/contact.html.twig', array (
				'number' => $slug 
		) );
	}
	
	/**
	 *
	 * para probar una variable enrutada por el routing.yml
	 *
	 * @param unknown $slug        	
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function aboutAction($slug) {
		return $this->render ( 'blog/about.html.twig', array (
				'number' => $slug 
		) );
	}
	/**
	 *
	 * para probar una variable enrutada por el routing.yml
	 *
	 * @param unknown $slug        	
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function blogAction($slug) {
		return $this->render ( 'blog/blog.html.twig', array (
				'number' => $slug 
		) );
	}
	public function getMenuAction() {
		try {
			$menuservice = $this->get ( 'app.menu.service' );
			$menu = $menuservice->getMenuService ();
			
			return $this->render ( 'blog/menu.html.twig', array (
					'menu' => $menu 
			) );
		} catch ( \UnexpectedValueException $e ) {
			return new Response ($e->getMessage());
		} catch ( \Exception $e ) {
			return new Response("");
		}
	}
}