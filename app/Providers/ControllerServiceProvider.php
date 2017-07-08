<?php
namespace Intraxia\Gistpen\Providers;

use Intraxia\Gistpen\Http\CommitController;
use Intraxia\Gistpen\Http\JobController;
use Intraxia\Gistpen\Http\SearchController;
use Intraxia\Gistpen\Http\SiteController;
use Intraxia\Gistpen\Http\UserController;
use Intraxia\Gistpen\Http\RepoController;
use Intraxia\Gistpen\Http\BlobController;
use Intraxia\Jaxion\Contract\Core\Container;
use Intraxia\Jaxion\Contract\Core\ServiceProvider;

/**
 * Class ControllerServiceProvider
 *
 * @package Intraxia\Gistpen
 * @subpackage Providers
 */
class ControllerServiceProvider implements ServiceProvider {
	/**
	 * {@inheritdoc}
	 *
	 * @param Container $container
	 */
	public function register( Container $container ) {
		$container->share( array( 'controller.search' => 'Intraxia\Gistpen\Http\SearchController' ), function ( $app ) {
			return new SearchController( $app->fetch( 'database' ) );
		} );

		$container->share( array( 'controller.user' => 'Intraxia\Gistpen\Http\UserController' ), function ( $app ) {
			return new UserController( $app->fetch( 'options.user' ) );
		} );

		$container->share( array( 'controller.site' => 'Intraxia\Gistpen\Http\SiteController' ), function ( $app ) {
			return new SiteController( $app->fetch( 'options.site' ) );
		} );

		$container->share( array( 'controller.job' => 'Intraxia\Gistpen\Http\JobController' ), function ( $app ) {
			return new JobController;
		} );

		$container->share( array( 'controller.repo' => 'Intraxia\Gistpen\Http\RepoController' ), function ( $app ) {
			return new RepoController( $app->fetch( 'database' ) );
		} );
		$container->share( array( 'controller.blob' => 'Intraxia\Gistpen\Http\BlobController' ), function ( $app ) {
			return new BlobController( $app->fetch( 'database' ) );
		} );
		$container->share( array( 'controller.commit' => 'Intraxia\Gistpen\Http\BlobController' ), function( $app ) {
			return new CommitController( $app->fetch( 'database' ) );
		} );

	}
}
