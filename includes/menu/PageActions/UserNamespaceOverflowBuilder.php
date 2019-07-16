<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Minerva\Menu\PageActions;

use Hooks;
use MediaWiki\Minerva\LanguagesHelper;
use MediaWiki\Minerva\Menu\Group;
use MediaWiki\Minerva\Menu\Entries\LanguageSelectorEntry;
use MediaWiki\Minerva\Permissions\IMinervaPagePermissions;
use MediaWiki\Minerva\SkinUserPageHelper;
use MessageLocalizer;
use MinervaUI;
use MWException;
use Title;
use SpecialPage;
use User;

class UserNamespaceOverflowBuilder implements IOverflowBuilder {

	/**
	 * @var MessageLocalizer
	 */
	private $messageLocalizer;

	/**
	 * @var User|null
	 */
	private $pageUser;

	/**
	 * @var Title
	 */
	private $title;

	/**
	 * @var IMinervaPagePermissions
	 */
	private $permissions;

	/**
	 * @var LanguagesHelper
	 */
	private $languagesHelper;

	/**
	 * Initialize the overflow menu visible on the User namespace
	 * @param Title $title
	 * @param MessageLocalizer $msgLocalizer
	 * @param SkinUserPageHelper $userPageHelper
	 * @param IMinervaPagePermissions $permissions
	 * @param LanguagesHelper $languagesHelper
	 */
	public function __construct(
		Title $title,
		MessageLocalizer $msgLocalizer,
		SkinUserPageHelper $userPageHelper,
		IMinervaPagePermissions $permissions,
		LanguagesHelper $languagesHelper
	) {
		$this->title = $title;
		$this->messageLocalizer = $msgLocalizer;
		$this->pageUser = $userPageHelper->getPageUser();
		$this->permissions = $permissions;
		$this->languagesHelper = $languagesHelper;
	}

	/**
	 * @inheritDoc
	 * @throws MWException
	 */
	public function getGroup( array $toolbox ): Group {
		$group = new Group();
		if ( $this->permissions->isAllowed( IMinervaPagePermissions::SWITCH_LANGUAGE ) ) {
			$group->insertEntry( new LanguageSelectorEntry(
				$this->title,
				$this->languagesHelper->doesTitleHasLanguagesOrVariants( $this->title ),
				$this->messageLocalizer,
				MinervaUI::iconClass( 'language-switcher-base20',  'before',
					'minerva-page-actions-language-switcher toggle-list-item__anchor--menu' ),
				'minerva-page-actions-language-switcher'
			) );
		}
		$group->insertEntry( $this->build(
			'uploads', 'upload', SpecialPage::getTitleFor( 'Uploads', $this->pageUser )->getLocalURL()
		) );

		$possibleEntries = array_filter( [
			$this->buildFromToolbox( 'user-rights', 'userAvatar', 'userrights', $toolbox ),
			$this->buildFromToolbox( 'logs', 'listBullet', 'log', $toolbox ),
			$this->buildFromToolbox( 'info', 'info', 'info', $toolbox ),
			$this->buildFromToolbox( 'permalink', 'link', 'permalink', $toolbox ),
			$this->buildFromToolbox( 'backlinks', 'articleRedirect', 'whatlinkshere', $toolbox )
		] );

		foreach ( $possibleEntries as $menuEntry ) {
			$group->insertEntry( $menuEntry );
		}
		Hooks::run( 'MobileMenu', [ 'pageactions.overflow', &$group ] );
		return $group;
	}

	/**
	 * Build entry based on the $toolbox element
	 *
	 * @param string $name
	 * @param string $icon Wikimedia UI icon name.
	 * @param string $toolboxIdx
	 * @param array $toolbox An array of common toolbox items from the sidebar menu
	 * @return PageActionMenuEntry|null
	 */
	private function buildFromToolbox( $name, $icon, $toolboxIdx, array $toolbox ) {
		return $this->build( $name, $icon, $toolbox[$toolboxIdx]['href'] ?? null );
	}

	/**
	 * Build single Menu entry
	 *
	 * @param string $name
	 * @param string $icon Wikimedia UI icon name.
	 * @param string|null $href
	 * @return PageActionMenuEntry|null
	 */
	private function build( $name, $icon, $href ) {
		return $href ?
			new PageActionMenuEntry(
				'page-actions-overflow-' . $name,
				$href,
				MinervaUI::iconClass( '', 'before',
					'wikimedia-ui-' . $icon . '-base20 toggle-list-item__anchor--menu'
				),
				$this->messageLocalizer->msg( 'minerva-page-actions-' . $name )
			) : null;
	}
}
