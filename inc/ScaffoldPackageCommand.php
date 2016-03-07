<?php

namespace WP_CLI;

use WP_CLI;
use WP_CLI\Process;
use WP_CLI\Utils;

class ScaffoldPackageCommand {

	/**
	 * Generate the files needed for a basic WP-CLI command.
	 *
	 * <dir>
	 * : Directory for the new package.
	 *
	 * [--name=<name>]
	 * : Name to appear in the composer.json.
	 *
	 * [--description=<description>]
	 * : Human-readable description for the package.
	 *
	 * [--license=<license>]
	 * : License for the package. Default: MIT.
	 *
	 * [--skip-tests]
	 * : Don't generate files for integration testing.
	 *
	 * [--force]
	 * : Overwrite files that already exist.
	 *
	 * @when before_wp_load
	 */
	public function package( $args, $assoc_args ) {

		list( $package_dir ) = $args;

		$defaults = array(
			'name'        => '',
			'description' => '',
			'license'     => 'MIT',
		);
		$assoc_args = array_merge( $defaults, $assoc_args );
		$force = Utils\get_flag_value( $assoc_args, 'force' );

		$template_path = dirname( dirname( __FILE__ ) ) . '/templates/';

		$files_written = $this->create_files( array(
			"{$package_dir}/.gitignore" => Utils\mustache_render( "{$template_path}/gitignore.mustache", $assoc_args ),
			"{$package_dir}/.editorconfig" => Utils\mustache_render( "{$template_path}/editorconfig.mustache", $assoc_args ),
			"{$package_dir}/wp-cli.yml" => Utils\mustache_render( "{$template_path}/wp-cli.mustache", $assoc_args ),
			"{$package_dir}/command.php" => Utils\mustache_render( "{$template_path}/command.mustache", $assoc_args ),
			"{$package_dir}/composer.json" => Utils\mustache_render( "{$template_path}/composer.mustache", $assoc_args ),
		), $force );

		if ( empty( $files_written ) ) {
			WP_CLI::log( 'All package files were skipped.' );
		} else {
			WP_CLI::success( 'Created package files.' );
		}

		if ( ! Utils\get_flag_value( $assoc_args, 'skip-tests' ) ) {
			WP_CLI::run_command( array( 'scaffold', 'package-tests', $package_dir ), array( 'force' => $force ) );
		}
	}

	/**
	 * Generate files needed for writing Behat tests for your command.
	 *
	 * ## DESCRIPTION
	 *
	 * These are the files that are generated:
	 *
	 * * `.travis.yml` is the configuration file for Travis CI
	 * * `bin/install-package-tests.sh` will configure environment to run tests. Script expects WP_CLI_BIN_DIR and WP_CLI_CONFIG_PATH environment variables.
	 * * `features/load-wp-cli.feature` is a basic test to confirm WP-CLI can load.
	 * * `features/bootstrap`, `features/steps`, `features/extra` are Behat configuration files.
	 *
	 * ## ENVIRONMENT
	 *
	 * The `features/bootstrap/FeatureContext.php` file expects the WP_CLI_BIN_DIR and WP_CLI_CONFIG_PATH environment variables.
	 *
	 * WP-CLI Behat framework uses Behat ~2.5.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : The package directory to generate tests for.
	 *
	 * [--force]
	 * : Overwrite files that already exist.
	 *
	 * ## EXAMPLE
	 *
	 *     wp scaffold package-tests /path/to/command/dir/
	 *
	 * @when       before_wp_load
	 * @subcommand package-tests
	 */
	public function package_tests( $args, $assoc_args ) {
		list( $package_dir ) = $args;

		if ( is_file( $package_dir ) ) {
			$package_dir = dirname( $package_dir );
		} else if ( is_dir( $package_dir ) ) {
			$package_dir = rtrim( $package_dir, '/' );
		}

		if ( ! is_dir( $package_dir ) || ! file_exists( $package_dir . '/composer.json' ) ) {
			WP_CLI::error( "Invalid package directory. composer.json file must be present." );
		}

		$package_dir .= '/';
		$bin_dir       = $package_dir . 'bin/';
		$utils_dir     = $package_dir . 'utils/';
		$features_dir  = $package_dir . 'features/';
		$bootstrap_dir = $features_dir . 'bootstrap/';
		$steps_dir     = $features_dir . 'steps/';
		$extra_dir     = $features_dir . 'extra/';
		foreach ( array( $features_dir, $bootstrap_dir, $steps_dir, $extra_dir, $utils_dir, $bin_dir ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				Process::create( Utils\esc_cmd( 'mkdir %s', $dir ) )->run();
			}
		}

		$wp_cli_root = WP_CLI_ROOT;
		$package_root = dirname( dirname( __FILE__ ) );
		$copy_source = array(
			$wp_cli_root => array(
				'features/bootstrap/FeatureContext.php'       => $bootstrap_dir,
				'features/bootstrap/support.php'              => $bootstrap_dir,
				'php/WP_CLI/Process.php'                      => $bootstrap_dir,
				'php/utils.php'                               => $bootstrap_dir,
				'ci/behat-tags.php'                           => $utils_dir,
				'features/steps/given.php'                    => $steps_dir,
				'features/steps/when.php'                     => $steps_dir,
				'features/steps/then.php'                     => $steps_dir,
				'features/extra/no-mail.php'                  => $extra_dir,
			),
			$package_root => array(
				'.travis.yml'                                 => $package_dir,
				'templates/load-wp-cli.feature'               => $features_dir,
				'bin/install-package-tests.sh'                => $bin_dir,
			),
		);

		$files_written = array();
		foreach( $copy_source as $source => $to_copy ) {
			foreach ( $to_copy as $file => $dir ) {
				// file_get_contents() works with Phar-archived files
				$contents  = file_get_contents( $source . "/{$file}" );
				$file_path = $dir . basename( $file );

				$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force' );
				$should_write_file = $this->prompt_if_files_will_be_overwritten( $file_path, $force );
				if ( ! $should_write_file ) {
					continue;
				}
				$files_written[] = $file_path;

				$result = Process::create( Utils\esc_cmd( 'touch %s', $file_path ) )->run();
				file_put_contents( $file_path, $contents );
				if ( 'bin/install-package-tests.sh' === $file ) {
					Process::create( Utils\esc_cmd( 'chmod +x %s', $file_path ) )->run();
				}
			}
		}

		if ( empty( $files_written ) ) {
			WP_CLI::log( 'All package test files were skipped.' );
		} else {
			WP_CLI::success( 'Created package test files.' );
		}
	}

	private function prompt_if_files_will_be_overwritten( $filename, $force ) {
		$should_write_file = true;
		if ( ! file_exists( $filename ) ) {
			return true;
		}

		WP_CLI::warning( 'File already exists' );
		WP_CLI::log( $filename );
		if ( ! $force ) {
			do {
				$answer = \cli\prompt(
					'Skip this file, or replace it with scaffolding?',
					$default = false,
					$marker = '[s/r]: '
				);
			} while ( ! in_array( $answer, array( 's', 'r' ) ) );
			$should_write_file = 'r' === $answer;
		}

		$outcome = $should_write_file ? 'Replacing' : 'Skipping';
		WP_CLI::log( $outcome . PHP_EOL );

		return $should_write_file;
	}

	private function create_files( $files_and_contents, $force ) {
		$wrote_files = array();

		foreach ( $files_and_contents as $filename => $contents ) {
			$should_write_file = $this->prompt_if_files_will_be_overwritten( $filename, $force );
			if ( ! $should_write_file ) {
				continue;
			}

			if ( ! is_dir( dirname( $filename ) ) ) {
				Process::create( Utils\esc_cmd( 'mkdir -p %s', dirname( $filename ) ) )->run();
			}

			if ( ! file_put_contents( $filename, $contents ) ) {
				WP_CLI::error( "Error creating file: $filename" );
			} elseif ( $should_write_file ) {
				$wrote_files[] = $filename;
			}
		}
		return $wrote_files;
	}

}
