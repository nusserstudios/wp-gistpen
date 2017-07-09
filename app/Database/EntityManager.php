<?php
namespace Intraxia\Gistpen\Database;

use Exception;
use Intraxia\Gistpen\Model\Blob;
use Intraxia\Gistpen\Model\Commit;
use Intraxia\Gistpen\Model\Language;
use Intraxia\Gistpen\Model\Repo;
use Intraxia\Gistpen\Model\State;
use Intraxia\Jaxion\Axolotl\Collection;
use Intraxia\Jaxion\Axolotl\GuardedPropertyException;
use Intraxia\Jaxion\Axolotl\Model;
use Intraxia\Jaxion\Contract\Axolotl\EntityManager as EntityManagerContract;
use InvalidArgumentException;
use ReflectionClass;
use stdClass;
use WP_Error;
use WP_Query;
use WP_Term;

class EntityManager implements EntityManagerContract {
	/**
	 * Model class for the Repo.
	 */
	const REPO_CLASS = 'Intraxia\Gistpen\Model\Repo';

	/**
	 * Model class for the Repo.
	 */
	const BLOB_CLASS = 'Intraxia\Gistpen\Model\Blob';

	/**
	 * Model class for the Repo.
	 */
	const LANGUAGE_CLASS = 'Intraxia\Gistpen\Model\Language';

	/**
	 * Model class for the Commit.
	 */
	const COMMIT_CLASS = 'Intraxia\Gistpen\Model\Commit';

	/**
	 * Model class for the Commit.
	 */
	const STATE_CLASS = 'Intraxia\Gistpen\Model\State';

	/**
	 * Meta prefix.
	 *
	 * @var string
	 */
	protected $prefix;

	/**
	 * EntityManager constructor.
	 *
	 * @param string $prefix Meta prefix for entities.
	 */
	public function __construct( $prefix ) {
		$this->prefix = $prefix;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $class Fully qualified class name of model.
	 * @param int    $id    ID of the model.
	 *
	 * @return Model|WP_Error
	 */
	public function find( $class, $id, array $params = array() ) {
		if ( ! isset( $params['with'] ) ) {
			$params['with'] = array();
		}

		if ( is_string( $params['with'] ) ) {
			$params['with'] = array( $params['with'] => array() );
		}

		if ( ! is_array( $params['with'] ) ) {
			throw new InvalidArgumentException( 'with' );
		}

		$reflection = new ReflectionClass( $class );

		if ( ! $reflection->isSubclassOf( 'Intraxia\Jaxion\Axolotl\Model' ) ) {
			return new WP_Error( 'Invalid model' );
		}

		switch ( true ) {
			case $reflection->implementsInterface( 'Intraxia\Jaxion\Contract\Axolotl\UsesWordPressPost'):
				$post_type = $reflection->getMethod( 'get_post_type' )->invoke( null );
				// @todo validate post_parent === 0 if used for relation
				$post  = get_post( $id );

				if ( ! $post || $post->post_type !== $post_type ) {
					return new WP_Error( 'Invalid id' );
				}

				/** @var Model $model */
				$model = $reflection->newInstance( array( Model::OBJECT_KEY => $post ) );
				$table = array();

				foreach ( $model->get_table_keys() as $key ) {
					if ( 'states' === $key ) {
						$table[ $key ] = new Collection( self::STATE_CLASS );
					}

					// @todo handle related keys specially for now.
					if ( in_array( $key, array( 'blobs', 'language', 'states' ) ) ) {
						continue;
					}

					$value = $table[ $key ] = get_metadata( 'post', $id, $this->make_meta_key( $key ), true );

					// @todo enable custom getter/setter in models
					if ( $key === 'sync' && ! $value ) {
						$table[ $key ] = 'off';
					}

					// Fallback for legacy metadata
					// @todo move to migration
					if ( $key === 'state_ids' ) {
						$value = get_metadata( 'post', $id, '_wpgp_commit_meta', true);

						if ( is_array( $value ) && isset( $value['state_ids'] ) ) {
							$model->set_attribute(
								$key,
								$value['state_ids']
							);

							delete_metadata( 'post', $id, '_wpgp_commit_meta'. true );
						}
					}
				}
				break;
			case $reflection->implementsInterface( 'Intraxia\Jaxion\Contract\Axolotl\UsesWordPressTerm'):
				$taxonomy = $reflection->getMethod( 'get_taxonomy' )->invoke( null );
				$term  = get_term( $id, $taxonomy );

				if ( ! $term ) {
					$term = new WP_Error( 'Error getting term' );
				}

				if ( is_wp_error( $term ) ) {
					return $term;
				}

				/** @var Model $model */
				$model = $reflection->newInstance( array( Model::OBJECT_KEY => $term ) );
				$table = array();

				foreach ( $model->get_table_keys() as $key ) {
					switch ( $key ) {
						default:
							$table[ $key ] = get_term_meta( $term->term_id, $this->make_meta_key( $key ), true );
					}
				}
				break;
			default:
				throw new Exception('Misconfigured Model' );
		}

		$model->set_attribute( Model::TABLE_KEY, $table );

		foreach ( $params['with'] as $key => $params ) {
			$value = null;

			switch ( $key ) {
				case 'blobs';
					$value = $this->find_by( self::BLOB_CLASS, array_merge( $params, array(
						'post_parent' => $id,
						'post_status' => $post->post_status,
						'order'       => 'ASC',
						'orderby'     => 'date',
					) ) );
					break;
				case 'language':
					$terms = get_the_terms( $post->ID, "{$this->prefix}_language" );

					if ( $terms ) {
						$term = array_pop( $terms );
					} else {
						$term       = new WP_Term( new stdClass );
						$term->slug = 'none';
					}

					$value = new Language( array( Model::OBJECT_KEY => $term ) );
					break;
				case 'states':
					$value = new Collection( self::STATE_CLASS );

					foreach ( $model->state_ids as $state_id ) {
						/** @var State|WP_Error $state */
						$state = $this->find( self::STATE_CLASS, $state_id, $params );

						if ( ! is_wp_error( $state ) ) {
							$value = $value->add( $state );
						}
					}
					break;
			}

			if ( null !== $value ) {
				$table[ $key ] = $value;
			}
		}

		$model->set_attribute( Model::TABLE_KEY, $table );
		$model->sync_original();

		return $model;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $class  Fully qualified class name of models to find.
	 * @param array  $params Params to constrain the find.
	 *
	 * @return Collection|WP_Error
	 */
	public function find_by( $class, array $params = array() ) {
		if ( static::REPO_CLASS === $class ) {
			return $this->find_repos_by( $params );
		}

		if ( static::BLOB_CLASS === $class ) {
			return $this->find_blobs_by( $params );
		}

		if ( static::LANGUAGE_CLASS === $class ) {
			return $this->find_languages_by( $params );
		}

		if ( static::COMMIT_CLASS === $class ) {
			return $this->find_commits_by( $params );
		}

		if ( static::STATE_CLASS === $class ) {
			return $this->find_states_by( $params );
		}

		return new WP_Error( 'Invalid class' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $class
	 * @param array  $data
	 *
	 * @return Model|WP_Error
	 */
	public function create( $class, array $data = array() ) {
		if ( static::REPO_CLASS === $class ) {
			return $this->create_repo( $data );
		}

		if ( static::BLOB_CLASS === $class ) {
			return $this->create_blob( $data );
		}

		if ( static::LANGUAGE_CLASS === $class ) {
			return $this->create_language( $data );
		}

		if ( static::COMMIT_CLASS === $class ) {
			return $this->create_commit( $data );
		}

		if ( static::STATE_CLASS === $class ) {
			return $this->create_state( $data );
		}

		return new WP_Error( 'Invalid class' );
	}

	/**
	 * @inheritDoc
	 */
	public function persist( Model $model ) {
		if ( $model instanceof Repo ) {
			return $this->persist_repo( $model );
		}

		if ( $model instanceof Blob ) {
			return $this->persist_blob( $model );
		}

		if ( $model instanceof Language ) {
			return $this->persist_language( $model );
		}

		if ( $model instanceof Commit ) {
			return $this->persist_commit( $model );
		}

		if ( $model instanceof State ) {
			return $this->persist_state( $model );
		}

		return new WP_Error( 'Invalid class' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Model $model
	 * @param bool  $force
	 *
	 * @return WP_Error|mixed
	 */
	public function delete( Model $model, $force = false ) {
		if ( $model instanceof Repo ) {
			return $this->delete_repo( $model, $force );
		}

		if ( $model instanceof Blob ) {
			return $this->delete_blob( $model, $force );
		}

		if ( $model instanceof Language ) {
			return $this->delete_language( $model, $force );
		}

		return new WP_Error( 'Invalid class' );
	}

	/**
	 * Queries for repos by the provided params.
	 *
	 * @param array $params Query parameters.
	 *
	 * @return Collection<Repo>
	 */
	protected function find_repos_by( array $params = array() ) {
		$collection = new Collection( self::REPO_CLASS );
		$query      = new WP_Query( array_merge( $params, array(
			'post_type'   => 'gistpen',
			'post_parent' => 0,
		) ) );

		foreach ( $query->get_posts() as $post ) {
			$repo = $this->find( self::REPO_CLASS, $post->ID, array(
				'with' => 'blobs',
			) );

			if ( ! is_wp_error( $repo ) ) {
				$collection = $collection->add( $repo );
			}
		}

		return $collection;
	}

	/**
	 * Queries for Blobs by the provided params.
	 *
	 * @param array $params Query parameters.
	 *
	 * @return Collection<Blob>
	 */
	protected function find_blobs_by( array $params = array() ) {
		$collection = new Collection( self::BLOB_CLASS );
		$query      = new WP_Query( array_merge( $params, array(
			'post_type'           => 'gistpen',
			'post_parent__not_in' => array( 0 ),
		) ) );

		foreach ( $query->get_posts() as $post ) {
			$blob = $this->find( self::BLOB_CLASS, $post->ID, $params );

			if ( ! is_wp_error( $blob ) ) {
				$collection = $collection->add( $blob );
			}
		}

		return $collection;
	}

	/**
	 * Queries for Languages by the provided params.
	 *
	 * @param array $params Query parameters.
	 *
	 * @return Collection<Language>
	 */
	protected function find_languages_by( $params = array() ) {
		$collection = new Collection( self::LANGUAGE_CLASS );

		$query = new \WP_Term_Query( array_merge( $params, array(
			'taxonomy'   => 'wpgp_language',
			'hide_empty' => false,
		) ) );

		/** WP_Term $term */
		foreach ( $query->get_terms() as $term ) {
			$language = $this->find( self::LANGUAGE_CLASS, $term->term_id );

			if ( ! is_wp_error( $language ) ) {
				$collection = $collection->add( $language );
			}
		}

		return $collection;
	}

	/**
	 * Queries for Commits by the provided params.
	 *
	 * @param array $params Query parameters.
	 *
	 * @return Collection<Commit>
	 */
	public function find_commits_by( $params = array() ) {
		$collection = new Collection( self::COMMIT_CLASS );

		$query      = new WP_Query( array_merge( $params, array(
			'post_type'           => 'revision',
			'post_parent' => $params['repo_id'],
		) ) );

		foreach ( $query->get_posts() as $post ) {
			$commit = $this->find( self::COMMIT_CLASS, $post->ID, $params );

			if ( ! is_wp_error( $commit ) ) {
				$collection = $collection->add( $commit );
			}
		}

		return $collection;
	}

	/**
	 * Queries for States by the provided params.
	 *
	 * @param array $params Query parameters.
	 *
	 * @return Collection<State>
	 */
	public function find_states_by( $params = array() ) {
		$collection = new Collection( self::STATE_CLASS );

		$query      = new WP_Query( array_merge( $params, array(
			'post_type'   => 'revision',
			'post_parent' => $params['blob_id'],
		) ) );

		foreach ( $query->get_posts() as $post ) {
			$commit = $this->find( self::STATE_CLASS, $post->ID, $params );

			if ( ! is_wp_error( $commit ) ) {
				$collection = $collection->add( $commit );
			}
		}

		return $collection;
	}

	/**
	 * Persist a new Repo
	 *
	 * @param array $data
	 *
	 * @return Repo|WP_Error
	 */
	protected function create_repo( array $data ) {
		$model = new Repo();
		$blobs = new Collection( self::BLOB_CLASS );

		/**
		 * Set aside the `blobs` key for use.
		 */
		$blobs_data = array();
		if ( isset( $data['blobs'] ) ) {
			if ( is_array( $data['blobs'] ) ) {
				$blobs_data = $data['blobs'];
			}

			unset( $data['blobs'] );
		}

		foreach ( $data as $key => $value ) {
			switch ( $key ) {
				default:
					try {
						$model->set_attribute( $key, $value );
					} catch ( GuardedPropertyException $exception ) {
						// @todo Ignore the value?
					}
					break;
			}
		}

		$result = wp_insert_post( (array) $model->get_underlying_wp_object(), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$model->set_attribute( Model::OBJECT_KEY, get_post( $result ) );

		foreach ( $model->get_table_attributes() as $key => $attribute ) {
			update_post_meta(
				$model->get_primary_id(),
				$this->make_meta_key( $key ),
				$attribute
			);
		}

		foreach ( $blobs_data as $blob_data ) {
			$blob_data['repo_id'] = $model->get_primary_id();
			$blob_data['status'] = $model->get_attribute( 'status' );

			$blob = $this->create_blob( $blob_data, array(
				'unguarded' => true,
			) );

			if ( ! is_wp_error( $blob ) ) {
				$blobs->add( $blob );
			}
		}

		$model->set_attribute( 'blobs', $blobs );

		return $model;
	}

	/**
	 * Creates a new blob with the provided data.
	 *
	 * @param array $data Blob data.
	 * @param array $options Options array.
	 *
	 * @return Blob|WP_Error
	 */
	protected function create_blob( array $data, array $options = array() ) {
		$model = new Blob;
		$unguarded = isset( $options['unguarded'] ) && $options['unguarded'];

		/**
		 * Set aside the `language` key for use.
		 */
		$language_data = array();
		if ( isset( $data['language'] ) ) {
			if ( is_array( $data['language'] ) ) {
				$language_data = $data['language'];
			}

			unset( $data['language'] );
		}

		foreach ( $data as $key => $value ) {
			try {
				if ( $unguarded ) {
					$model->unguard();
				}

				$model->set_attribute( $key, $value );

				if ( $unguarded ) {
					$model->reguard();
				}
			} catch ( GuardedPropertyException $exception ) {
				// @todo Ignore the value?
			}
		}

		$result = wp_insert_post( (array) $model->get_underlying_wp_object(), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$model->set_attribute( Model::OBJECT_KEY, get_post( $result ) );

		foreach ( $model->get_table_attributes() as $key => $attribute ) {
			update_post_meta(
				$model->get_primary_id(),
				$this->make_meta_key( $key ),
				$attribute
			);
		}

		$language = $this->find_languages_by( array( 'slug' => $language_data['slug'] ) );

		if ( count( $language ) === 0 ) {
			$language = $this->create_language( $language_data );

			if ( is_wp_error( $language ) ) {
				return $language;
			}
		} else {
			$language = $language->at( 0 );
		}

		$model->set_attribute( 'language', $language );

		wp_set_object_terms( $model->get_primary_id(), $model->language->slug, Language::get_taxonomy(), false );

		return $model;
	}

	/**
	 * Creates a new Language with the provided data.
	 *
	 * @param array $data Data to create language.
	 *
	 * @return Language|WP_Error
	 */
	protected function create_language( array $data ) {
		$model = new Language;

		foreach ( $data as $key => $value ) {
			try {
				$model->set_attribute( $key, $value );
			} catch ( GuardedPropertyException $exception ) {
				// @todo Ignore the value?
			}
		}

		$result = wp_insert_term( $model->slug, "{$this->prefix}_language" );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$model->set_attribute( Model::OBJECT_KEY, get_term( $result['term_id'] ) );

		foreach ( $model->get_table_attributes() as $key => $attribute ) {
			update_post_meta(
				$model->get_primary_id(),
				$this->make_meta_key( $key ),
				$attribute
			);
		}

		return $model;
	}

	/**
	 * Creates a new Commit with the provided data.
	 *
	 * @param array $data Data to create commit.
	 *
	 * @return Commit|WP_Error
	 */
	public function create_commit( array $data ) {
		$model = new Commit;

		foreach ( $data as $key => $value ) {
			switch ( $key ) {
				default:
					try {
						$model->set_attribute( $key, $value );
					} catch ( GuardedPropertyException $exception ) {
						// @todo Ignore the value?
					}
					break;
			}
		}

		$result = wp_insert_post( (array) $model->get_underlying_wp_object(), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$model->set_attribute( Model::OBJECT_KEY, get_post( $result ) );

		foreach ( $model->get_table_attributes() as $key => $attribute ) {
			update_metadata(
				'post',
				$model->get_primary_id(),
				$this->make_meta_key( $key ),
				$attribute
			);
		}

		return $model;
	}

	/**
	 * Creates a new State with the provided data.
	 *
	 * @param array $data Data to create state.
	 *
	 * @return state|WP_Error
	 */
	public function create_state( array $data ) {
		$model = new State;

		/**
		 * Set aside the `language` key for use.
		 */
		$language_data = array();
		if ( isset( $data['language'] ) ) {
			if ( is_array( $data['language'] ) ) {
				$language_data = $data['language'];
			}

			unset( $data['language'] );
		}

		foreach ( $data as $key => $value ) {
			switch ( $key ) {
				default:
					try {
						$model->set_attribute( $key, $value );
					} catch ( GuardedPropertyException $exception ) {
						// @todo Ignore the value?
					}
					break;
			}
		}

		$result = wp_insert_post( (array) $model->get_underlying_wp_object(), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$model->set_attribute( Model::OBJECT_KEY, get_post( $result ) );

		foreach ( $model->get_table_attributes() as $key => $attribute ) {
			update_metadata(
				'post',
				$model->get_primary_id(),
				$this->make_meta_key( $key ),
				$attribute
			);
		}

		$language = $this->find_languages_by( array( 'slug' => $language_data['slug'] ) );

		if ( count( $language ) === 0 ) {
			$language = $this->create_language( $language_data );

			if ( is_wp_error( $language ) ) {
				return $language;
			}
		} else {
			$language = $language->at( 0 );
		}

		$model->set_attribute( 'language', $language );

		wp_set_object_terms( $model->get_primary_id(), $model->language->slug, Language::get_taxonomy(), false );

		return $model;
	}

	/**
	 * Updates a Repo to sync with the database.
	 *
	 * @param Repo $model
	 *
	 * @return Repo|WP_Error
	 */
	protected function persist_repo( Repo $model ) {
		$result  = wp_update_post( $model->get_underlying_wp_object(), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $model->get_changed_table_attributes() as $key => $value ) {
			update_post_meta( $model->get_primary_id() , "_{$this->prefix}_{$key}", $value );
		}

		$model->set_attribute( Model::OBJECT_KEY, get_post( $model->get_primary_id() ) );

		$deleted_blobs = $model->get_original_attribute( 'blobs' )
			->filter(function( Blob $original_blob ) use ( &$model ) {
				/** @var Blob $blob */
				foreach ( $model->blobs as $blob ) {
					if ( $blob->get_primary_id() === $original_blob->get_primary_id() ) {
						return false;
					}
				}

				return true;
			});

		/** @var Blob $blob */
		foreach ( $model->blobs as $blob ) {
			$blob->unguard();
			$blob->repo_id = $model->get_primary_id();
			$blob->status = $model->get_attribute( 'status' );
			$blob->reguard();

			$this->persist_blob( $blob );
		}

		/** @var Blob $deleted_blob */
		foreach ( $deleted_blobs as $deleted_blob ) {
			wp_trash_post( $deleted_blob->get_primary_id() );
		}

		return $this->find( static::REPO_CLASS, $model->get_primary_id() );
	}

	/**
	 * Updates a Blob to sync with the database.
	 *
	 * @param Repo $model
	 *
	 * @return Repo|WP_Error
	 */
	protected function persist_blob( Blob $model ) {
		$result  = $model->get_primary_id() ?
			wp_update_post( $model->get_underlying_wp_object(), true ) :
			wp_insert_post( (array) $model->get_underlying_wp_object(), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$model->set_attribute( Model::OBJECT_KEY, get_post( $result ) );

		foreach ( $model->get_changed_table_attributes() as $key => $value ) {
			update_post_meta( $model->get_primary_id(), $key, $value );
		}

		try {
			wp_set_object_terms( $model->get_primary_id(), $model->language->slug, Language::get_taxonomy(), false );
		} catch ( Exception $exception ) {
			// @todo what to do?
		}

		return $this->find( static::BLOB_CLASS, $model->get_primary_id() );
	}

	/**
	 * Updates a Language to sync with the database.
	 *
	 * @param Language $model
	 *
	 * @return Language|WP_Error
	 */
	protected function persist_language( Language $model ) {
		$result  = $model->get_primary_id() ?
			wp_update_term(
				$model->get_primary_id(),
				"{$this->prefix}_language",
				(array) $model->get_underlying_wp_object()
			) :
			wp_insert_term( $model->slug, "{$this->prefix}_language" );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$model->set_attribute( Model::OBJECT_KEY, get_term( $result['term_id'] ) );

		foreach ( $model->get_table_attributes() as $key => $attribute ) {
			update_post_meta(
				$model->get_primary_id(),
				$this->make_meta_key( $key ),
				$attribute
			);
		}

		return $this->find( static::LANGUAGE_CLASS, $model->get_primary_id() );
	}

	/**
	 * Updates a Repo to sync with the database.
	 *
	 * @param Commit $model
	 *
	 * @return Commit|WP_Error
	 */
	protected function persist_commit( Commit $model ) {
		$result  = wp_update_post( $model->get_underlying_wp_object(), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $model->get_changed_table_attributes() as $key => $value ) {
			update_metadata(
				'post',
				$model->get_primary_id() ,
				"_{$this->prefix}_{$key}",
				$value
			);
		}

		$model->set_attribute( Model::OBJECT_KEY, get_post( $model->get_primary_id() ) );

		return $this->find( static::COMMIT_CLASS, $model->get_primary_id() );
	}

	/**
	 * Updates a Repo to sync with the database.
	 *
	 * @param State $model
	 *
	 * @return State|WP_Error
	 */
	protected function persist_state( State $model ) {
		$result  = wp_update_post( $model->get_underlying_wp_object(), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( $model->get_changed_table_attributes() as $key => $value ) {
			update_metadata(
				'post',
				$model->get_primary_id() ,
				"_{$this->prefix}_{$key}",
				$value
			);
		}

		$model->set_attribute( Model::OBJECT_KEY, get_post( $model->get_primary_id() ) );

		return $this->find( static::STATE_CLASS, $model->get_primary_id() );
	}

	/**
	 * Deletes the Repo and all its associated Blobs.
	 *
	 * @param Repo $model
	 * @param bool $force
	 *
	 * @return Repo|WP_Error
	 */
	protected function delete_repo( Repo $model, $force ) {
		$id = $model->get_primary_id();

		if ( ! $id ) {
			return new WP_Error( __( 'Repo does not exist in the database.' ) );
		}

		$result = wp_delete_post( $id, $force );

		if ( ! $result ) {
			return new WP_Error( __( 'Failed to delete Repo from the Database.' ) );
		}

		foreach ( $model->blobs as $blob ) {
			$this->delete_blob( $blob, $force );
		}

		return $model;
	}

	/**
	 * Delete a Blob from the database.
	 *
	 * @param Blob $model
	 * @param bool $force
	 *
	 * @return Blob|WP_Error
	 */
	protected function delete_blob( Blob $model, $force ) {
		$id = $model->get_primary_id();

		if ( ! $id ) {
			return new WP_Error( __( 'Repo does not exist in the database.' ) );
		}

		$result = wp_delete_post( $id, $force );

		if ( ! $result ) {
			return new WP_Error( __( 'Failed to delete Repo from the Database.' ) );
		}

		return $model;
	}

	protected function delete_language( Language $model, $force ) {
		return new WP_Error( 'not implemented' );
	}

	/**
	 * Wraps the given key with the string required to make it a meta key.
	 *
	 * @param {string} $key Key to turn into meta key.
	 *
	 * @return string Generated meta key.
	 */
	protected function make_meta_key( $key ) {
		return "_{$this->prefix}_{$key}";
	}
}
