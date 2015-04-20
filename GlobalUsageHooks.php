<?php
/**
 * GlobalUsage hooks for updating globalimagelinks table.
 *
 * UI hooks in SpecialGlobalUsage.
 */

class GlobalUsageHooks {
	private static $gu = null;

	/**
	 * Hook to LinksUpdateComplete
	 * Deletes old links from usage table and insert new ones.
	 * @param $linksUpdater LinksUpdate
	 * @return bool
	 */
	public static function onLinksUpdateComplete( $linksUpdater ) {
		$title = $linksUpdater->getTitle();

		// Create a list of locally existing images (DB keys)
		$images = array_keys( $linksUpdater->getImages() );

		$localFiles = array();
		$repo = RepoGroup::singleton()->getLocalRepo();
		if ( defined( 'FileRepo::NAME_AND_TIME_ONLY' ) ) { // MW 1.23
			$imagesInfo = $repo->findFiles( $images, FileRepo::NAME_AND_TIME_ONLY );
			foreach ( $imagesInfo as $dbKey => $info ) {
				$localFiles[] = $dbKey;
				if ( $dbKey !== $info['title'] ) { // redirect
					$localFiles[] = $info['title'];
				}
			}
		} else {
			// Unrolling findFiles() here because pages with thousands of images trigger an OOM
			foreach ( $images as $dbKey ) {
				$file = $repo->findFile( $dbKey );
				if ( $file ) {
					$localFiles[] = $dbKey;
					if ( $file->getTitle()->getDBkey() !== $dbKey ) { // redirect
						$localFiles[] = $file->getTitle()->getDBkey();
					}
				}
			}
		}
		$localFiles = array_values( array_unique( $localFiles ) );

		$missingFiles = array_diff( $images, $localFiles );

		$gu = self::getGlobalUsage();
		$articleId = $title->getArticleID( Title::GAID_FOR_UPDATE );
		$existing = $gu->getLinksFromPage( $articleId );

		// Calculate changes
		$added = array_diff( $missingFiles, $existing );
		$removed = array_diff( $existing, $missingFiles );

		// Add new usages and delete removed
		$gu->insertLinks( $title, $added );
		if ( $removed ) {
			$gu->deleteLinksFromPage( $articleId, $removed );
		}

		return true;
	}

	/**
	 * Hook to TitleMoveComplete
	 * Sets the page title in usage table to the new name.
	 * For shared file moves, purges all pages in the wiki farm that use the files.
	 * @param $ot Title
	 * @param $nt Title
	 * @param $user User
	 * @param $pageid int
	 * @param $redirid
	 * @return bool
	 */
	public static function onTitleMoveComplete( $ot, $nt, $user, $pageid, $redirid ) {
		$gu = self::getGlobalUsage();
		$gu->moveTo( $pageid, $nt );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$jobs = array();
			if ( $ot->inNamespace( NS_FILE ) ) {
				$jobs[] = new GlobalUsageCachePurgeJob( $ot, array() );
			}
			if ( $nt->inNamespace( NS_FILE ) ) {
				$jobs[] = new GlobalUsageCachePurgeJob( $nt, array() );
			}
			JobQueueGroup::singleton()->push( $jobs );
		}

		return true;
	}

	/**
	 * Hook to ArticleDeleteComplete
	 * Deletes entries from usage table.
	 * @param $article Article
	 * @param $user User
	 * @param $reason string
	 * @param $id int
	 * @return bool
	 */
	public static function onArticleDeleteComplete( $article, $user, $reason, $id ) {
		$gu = self::getGlobalUsage();
		$gu->deleteLinksFromPage( $id );

		return true;
	}

	/**
	 * Hook to FileDeleteComplete
	 * Copies the local link table to the global.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param $file File
	 * @param $oldimage
	 * @param $article Article
	 * @param $user User
	 * @param $reason string
	 * @return bool
	 */
	public static function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ) {
		if ( !$oldimage ) {
			$gu = self::getGlobalUsage();
			$gu->copyLocalImagelinks( $file->getTitle() );

			if ( self::fileUpdatesCreatePurgeJobs() ) {
				$job = new GlobalUsageCachePurgeJob( $file->getTitle(), array() );
				JobQueueGroup::singleton()->push( $job );
			}
		}

		return true;
	}

	/**
	 * Hook to FileUndeleteComplete
	 * Deletes the file from the global link table.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param $title Title
	 * @param $versions
	 * @param $user User
	 * @param $reason string
	 * @return bool
	 */
	public static function onFileUndeleteComplete( $title, $versions, $user, $reason ) {
		$gu = self::getGlobalUsage();
		$gu->deleteLinksToFile( $title );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$job = new GlobalUsageCachePurgeJob( $title, array() );
			JobQueueGroup::singleton()->push( $job );
		}

		return true;
	}

	/**
	 * Hook to UploadComplete
	 * Deletes the file from the global link table.
	 * Purges all pages in the wiki farm that use the file if it is a shared repo file.
	 * @param $upload File
	 * @return bool
	 */
	public static function onUploadComplete( $upload ) {
		$gu = self::getGlobalUsage();
		$gu->deleteLinksToFile( $upload->getTitle() );

		if ( self::fileUpdatesCreatePurgeJobs() ) {
			$job = new GlobalUsageCachePurgeJob( $upload->getTitle(), array() );
			JobQueueGroup::singleton()->push( $job );
		}

		return true;
	}

	/**
	 *
	 * Check if file updates on this wiki should cause backlink page purge jobs
	 *
	 * @return bool
	 */
	private static function fileUpdatesCreatePurgeJobs() {
		global $wgGlobalUsageSharedRepoWiki, $wgGlobalUsagePurgeBacklinks;

		return ( $wgGlobalUsagePurgeBacklinks && wfWikiId() === $wgGlobalUsageSharedRepoWiki );
	}

	/**
	 * Initializes a GlobalUsage object for the current wiki.
	 *
	 * @return GlobalUsage
	 */
	private static function getGlobalUsage() {
		global $wgGlobalUsageDatabase;
		if ( is_null( self::$gu ) ) {
			self::$gu = new GlobalUsage( wfWikiId(),
				wfGetDB( DB_MASTER, array(), $wgGlobalUsageDatabase )
			);
		}

		return self::$gu;
	}

	/**
	 * Hook to make sure globalimagelinks table gets duplicated for parsertests
	 * @param $tables array
	 * @return bool
	 */
	public static function onParserTestTables( &$tables ) {
		$tables[] = 'globalimagelinks';
		return true;
	}

	/**
	 * Hook to apply schema changes
	 *
	 * @param $updater DatabaseUpdater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater = null ) {
		$dir = dirname( __FILE__ );

		if ( $updater->getDB()->getType() == 'mysql' || $updater->getDB()->getType() == 'sqlite' ) {
			$updater->addExtensionUpdate( array( 'addTable', 'globalimagelinks',
				"$dir/GlobalUsage.sql", true ) );
			$updater->addExtensionUpdate( array( 'addIndex', 'globalimagelinks',
				'globalimagelinks_wiki_nsid_title', "$dir/patches/patch-globalimagelinks_wiki_nsid_title.sql", true ) );
		} elseif ( $updater->getDB()->getType() == 'postgresql' ) {
			$updater->addExtensionUpdate( array( 'addTable', 'globalimagelinks',
				"$dir/GlobalUsage.pg.sql", true ) );
			$updater->addExtensionUpdate( array( 'addIndex', 'globalimagelinks',
				'globalimagelinks_wiki_nsid_title', "$dir/patches/patch-globalimagelinks_wiki_nsid_title.pg.sql", true ) );
		}
		return true;
	}

	public static function onwgQueryPages( $queryPages ) {
		$queryPages[] = array( 'MostGloballyLinkedFilesPage', 'MostGloballyLinkedFiles' );
		$queryPages[] = array( 'SpecialGloballyWantedFiles', 'GloballyWantedFiles' );
		return true;
	}
}
