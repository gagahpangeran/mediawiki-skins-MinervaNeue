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

use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Minerva\Menu\Main\MainMenuDirector;
use MediaWiki\Minerva\Permissions\IMinervaPagePermissions;
use MediaWiki\Minerva\SkinOptions;
use MediaWiki\Minerva\Skins\SkinUserPageHelper;

/**
 * Minerva: Born from the godhead of Jupiter with weapons!
 * A skin that works on both desktop and mobile
 * @ingroup Skins
 */
class SkinMinerva extends SkinMustache {
	/** @var array Cached array of content navigation URLs */
	private $contentNavigationUrls = [];
	/** @var array|null cached array of page action URLs */
	private $pageActionsMenu = null;

	/** @const LEAD_SECTION_NUMBER integer which corresponds to the lead section
	 * in editing mode
	 */
	public const LEAD_SECTION_NUMBER = 0;

	/** @var string Name of this skin */
	public $skinname = 'minerva';
	/** @var string Name of this used template */
	public $template = 'MinervaTemplate';

	/** @var SkinOptions */
	private $skinOptions;

	/**
	 * This variable is lazy loaded, please use getPermissions() getter
	 * @see SkinMinerva::getPermissions()
	 * @var IMinervaPagePermissions
	 */
	private $permissions;

	/**
	 * @return SkinOptions
	 */
	private function getSkinOptions() {
		if ( !$this->skinOptions ) {
			$this->skinOptions = MediaWikiServices::getInstance()->getService( 'Minerva.SkinOptions' );
		}
		return $this->skinOptions;
	}

	/**
	 * @return bool
	 */
	private function hasPageActions() {
		$title = $this->getTitle();
		return !$title->isSpecialPage() && !$title->isMainPage() &&
			Action::getActionName( $this->getContext() ) === 'view';
	}

	/**
	 * @return bool
	 */
	private function hasSecondaryActions() {
		return !$this->getUserPageHelper()->isUserPage();
	}

	/**
	 * @return bool
	 */
	private function isFallbackEditor() {
		$action = $this->getRequest()->getVal( 'action' );
		return $action === 'edit';
	}

	/**
	 * Returns available page actions if the page has any.
	 *
	 * @return array|null
	 */
	private function getPageActions() {
		return $this->isFallbackEditor() || !$this->hasPageActions() ?
			null : $this->pageActionsMenu;
	}

	/**
	 * @inheritDoc
	 */
	public function getTemplateData() {
			$data = parent::getTemplateData();
			if ( !$this->hasCategoryLinks() ) {
				unset( $data['html-categories'] );
			}

			$tpl = $this->prepareQuickTemplate();
			$tplData = $tpl->execute();
			return $data + $tplData + [
				'data-minerva-tabs' => $this->getTabsData(),
				'data-minerva-page-actions' => $this->getPageActions(),
				'data-minerva-secondary-actions' => $this->getSecondaryActions(),
				'html-minerva-subject-link' => $this->getSubjectPage(),
				'data-minerva-history-link' => $this->getHistoryLink( $this->getTitle() ),
			];
	}

	/**
	 * Tabs are available if a page has page actions but is not the talk page of
	 * the main page.
	 *
	 * Special pages have tabs if SkinOptions::TABS_ON_SPECIALS is enabled.
	 * This is used by Extension:GrowthExperiments
	 *
	 * @return bool
	 */
	private function hasPageTabs() {
		$title = $this->getTitle();
		$skinOptions = $this->getSkinOptions();
		$isSpecialPage = $title->isSpecialPage();
		$subjectPage = MediaWikiServices::getInstance()->getNamespaceInfo()
			->getSubjectPage( $title );
		$isMainPageTalk = Title::newFromLinkTarget( $subjectPage )->isMainPage();
		return (
				$this->hasPageActions() && !$isMainPageTalk
			) || (
				$isSpecialPage &&
				$skinOptions->get( SkinOptions::TABS_ON_SPECIALS )
			);
	}

	/**
	 * @return array
	 */
	private function getTabsData() {
		$skinOptions = $this->getSkinOptions();
		$hasTalkTabs = $skinOptions->get( SkinOptions::TALK_AT_TOP ) && $this->hasPageTabs();
		if ( !$hasTalkTabs ) {
			return [];
		}
		return $this->contentNavigationUrls ? [
			'items' => array_values( $this->contentNavigationUrls['namespaces'] ),
		] : [];
	}

	/**
	 * Lazy load the permissions object. We don't want to initialize it as it requires many
	 * dependencies, sometimes some of those dependencies cannot be fulfilled (like missing Title
	 * object)
	 * @return IMinervaPagePermissions
	 */
	private function getPermissions(): IMinervaPagePermissions {
		if ( $this->permissions === null ) {
			$this->permissions = MediaWikiServices::getInstance()
				->getService( 'Minerva.Permissions' )
				->setContext( $this->getContext() );
		}
		return $this->permissions;
	}

	/**
	 * Initalized main menu. Please use getter.
	 * @var MainMenuDirector
	 */
	private $mainMenu;

	/**
	 * Build the Main Menu Director by passing the skin options
	 *
	 * @return MainMenuDirector
	 */
	protected function getMainMenu(): MainMenuDirector {
		if ( !$this->mainMenu ) {
			$this->mainMenu = MediaWikiServices::getInstance()->getService( 'Minerva.Menu.MainDirector' );
		}
		return $this->mainMenu;
	}

	/**
	 * initialize various variables and generate the template
	 * @return QuickTemplate
	 * @suppress PhanTypeMismatchArgument
	 */
	protected function prepareQuickTemplate() {
		$out = $this->getOutput();

		// Generate skin template
		$tpl = parent::prepareQuickTemplate();

		// Construct various Minerva-specific interface elements
		$this->prepareMenus( $tpl );
		$this->prepareHeaderAndFooter( $tpl );
		$this->prepareBanners( $tpl );
		$this->prepareUserNotificationsButton( $tpl, $tpl->get( 'newtalk' ) );
		$this->prepareLanguages( $tpl );

		return $tpl;
	}

	/**
	 * Prepare all Minerva menus
	 * @param BaseTemplate $tpl
	 * @throws MWException
	 */
	private function prepareMenus( BaseTemplate $tpl ) {
		$services = MediaWikiServices::getInstance();
		/** @var \MediaWiki\Minerva\Menu\PageActions\PageActionsDirector $pageActionsDirector */
		$pageActionsDirector = $services->getService( 'Minerva.Menu.PageActionsDirector' );
		/** @var \MediaWiki\Minerva\Menu\User\UserMenuDirector $userMenuDirector */
		$userMenuDirector = $services->getService( 'Minerva.Menu.UserMenuDirector' );

		$sidebar = parent::buildSidebar();
		$personalUrls = $tpl->get( 'personal_urls' );
		$personalTools = $this->getSkin()->getPersonalToolsForMakeListItem( $personalUrls );
		$nav = $this->buildContentNavigationUrls();
		$actions = $nav['actions'] ?? [];
		$tpl->set( 'mainMenu', $this->getMainMenu()->getMenuData() );
		$this->contentNavigationUrls = $nav;
		$this->pageActionsMenu = $pageActionsDirector->buildMenu( $sidebar['TOOLBOX'], $actions );
		$tpl->set( 'userMenuHTML', $userMenuDirector->renderMenuData( $personalTools ) );
	}

	/**
	 * @return string
	 */
	protected function getSubjectPage() {
		$services = MediaWikiServices::getInstance();
		$title = $this->getTitle();
		$skinOptions = $this->getSkinOptions();

		// If it's a talk page, add a link to the main namespace page
		// In AMC we do not need to do this as there is an easy way back to the article page
		// via the talk/article tabs.
		if ( $title->isTalkPage() && !$skinOptions->get( SkinOptions::TALK_AT_TOP ) ) {
			// if it's a talk page for which we have a special message, use it
			switch ( $title->getNamespace() ) {
				case NS_USER_TALK:
					$msg = 'mobile-frontend-talk-back-to-userpage';
					break;
				case NS_PROJECT_TALK:
					$msg = 'mobile-frontend-talk-back-to-projectpage';
					break;
				case NS_FILE_TALK:
					$msg = 'mobile-frontend-talk-back-to-filepage';
					break;
				default: // generic (all other NS)
					$msg = 'mobile-frontend-talk-back-to-page';
			}
			$subjectPage = $services->getNamespaceInfo()->getSubjectPage( $title );

			return MediaWikiServices::getInstance()->getLinkRenderer()->makeLink(
				$subjectPage,
				$this->msg( $msg, $title->getText() )->text(),
				[
					'data-event-name' => 'talk.returnto',
					'class' => 'return-link'
				]
			);
		} else {
			return '';
		}
	}

	/**
	 * Overrides Skin::doEditSectionLink
	 * @param Title $nt The title being linked to (may not be the same as
	 *   the current page, if the section is included from a template)
	 * @param string $section
	 * @param string|null $tooltip
	 * @param Language $lang
	 * @return string
	 */
	public function doEditSectionLink( Title $nt, $section, $tooltip, Language $lang ) {
		if ( $this->getPermissions()->isAllowed( IMinervaPagePermissions::EDIT_OR_CREATE ) &&
			 !$nt->isMainPage() ) {
			$message = $this->msg( 'mobile-frontend-editor-edit' )->inLanguage( $lang )->text();
			$html = Html::openElement( 'span', [ 'class' => 'mw-editsection' ] );
			$html .= Html::element( 'a', [
				'href' => $nt->getLocalURL( [ 'action' => 'edit', 'section' => $section ] ),
				'title' => $this->msg( 'editsectionhint', $tooltip )->inLanguage( $lang )->text(),
				'data-section' => $section,
				// Note visibility of the edit section link button is controlled by .edit-page in ui.less so
				// we default to enabled even though this may not be true.
				'class' => MinervaUI::iconClass(
					'edit-base20', 'element', 'edit-page mw-ui-icon-flush-right', 'wikimedia'
				),
			], $message );
			$html .= Html::closeElement( 'span' );
			return $html;
		}
		return '';
	}

	/**
	 * Takes a title and returns classes to apply to the body tag
	 * @param Title $title
	 * @return string
	 */
	public function getPageClasses( $title ) {
		$skinOptions = $this->getSkinOptions();
		$className = parent::getPageClasses( $title );
		$className .= ' ' . ( $skinOptions->get( SkinOptions::BETA_MODE )
				? 'beta' : 'stable' );

		if ( $title->isMainPage() ) {
			$className .= ' page-Main_Page ';
		}

		if ( $this->getUser()->isRegistered() ) {
			$className .= ' is-authenticated';
		}
		// The new treatment should only apply to the main namespace
		if (
			$title->getNamespace() === NS_MAIN &&
			$skinOptions->get( SkinOptions::PAGE_ISSUES )
		) {
			$className .= ' issues-group-B';
		}
		return $className;
	}

	/**
	 * Whether the output page contains category links and the category feature is enabled.
	 * @return bool
	 */
	private function hasCategoryLinks() {
		$skinOptions = $this->getSkinOptions();
		if ( !$skinOptions->get( SkinOptions::CATEGORIES ) ) {
			return false;
		}
		$categoryLinks = $this->getOutput()->getCategoryLinks();

		if ( !count( $categoryLinks ) ) {
			return false;
		}
		return !empty( $categoryLinks['normal'] ) || !empty( $categoryLinks['hidden'] );
	}

	/**
	 * @return SkinUserPageHelper
	 */
	public function getUserPageHelper() {
		return MediaWikiServices::getInstance()->getService( 'Minerva.SkinUserPageHelper' );
	}

	/**
	 * Prepares the user button.
	 * @param QuickTemplate $tpl
	 * @param string $newTalks New talk page messages for the current user
	 */
	protected function prepareUserNotificationsButton( QuickTemplate $tpl, $newTalks ) {
		$user = $this->getUser();
		$currentTitle = $this->getTitle();
		$notificationsMsg = $this->msg( 'mobile-frontend-user-button-tooltip' )->text();
		$notificationIconClass = MinervaUI::iconClass( 'bellOutline-base20',
			'element', '', 'wikimedia' );

		if ( $user->isRegistered() ) {
			$badge = Html::element( 'a', [
				'class' => $notificationIconClass,
				'href' => SpecialPage::getTitleFor( 'Mytalk' )->getLocalURL(
					[ 'returnto' => $currentTitle->getPrefixedText() ]
				),
			], $notificationsMsg );
			Hooks::run( 'SkinMinervaReplaceNotificationsBadge',
				[ $user, $currentTitle, &$badge ] );
			$tpl->set( 'userNotificationsHTML', $badge );
		}
	}

	/**
	 * Rewrites the language list so that it cannot be contaminated by other extensions with things
	 * other than languages
	 * See bug 57094.
	 *
	 * @todo Remove when Special:Languages link goes stable
	 * @param QuickTemplate $tpl
	 */
	protected function prepareLanguages( $tpl ) {
		$lang = $this->getTitle()->getPageViewLanguage();
		$tpl->set( 'pageLang', $lang->getHtmlCode() );
		$tpl->set( 'pageDir', $lang->getDir() );
		// If the array is empty, then instead give the skin boolean false
		$language_urls = $this->getLanguages() ?: false;
		$tpl->set( 'language_urls', $language_urls );
	}

	/**
	 * Get a history link which describes author and relative time of last edit
	 * @param Title $title The Title object of the page being viewed
	 * @param string $timestamp
	 * @return array
	 */
	protected function getRelativeHistoryLink( Title $title, $timestamp ) {
		$user = $this->getUser();
		$userDate = $this->getLanguage()->userDate( $timestamp, $user );
		$text = $this->msg(
			'minerva-last-modified-date', $userDate,
			$this->getLanguage()->userTime( $timestamp, $user )
		)->parse();
		return [
			// Use $edit['timestamp'] (Unix format) instead of $timestamp (MW format)
			'data-timestamp' => wfTimestamp( TS_UNIX, $timestamp ),
			'href' => $this->getHistoryUrl( $title ),
			'text' => $text,
		] + $this->getRevisionEditorData( $title );
	}

	/**
	 * Get a history link which makes no reference to user or last edited time
	 * @param Title $title The Title object of the page being viewed
	 * @return array
	 */
	protected function getGenericHistoryLink( Title $title ) {
		$text = $this->msg( 'mobile-frontend-history' )->plain();
		return [
			'href' => $this->getHistoryUrl( $title ),
			'text' => $text,
		];
	}

	/**
	 * Get the URL for the history page for the given title using Special:History
	 * when available.
	 * @param Title $title The Title object of the page being viewed
	 * @return string
	 */
	protected function getHistoryUrl( Title $title ) {
		return ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' ) &&
			SpecialMobileHistory::shouldUseSpecialHistory( $title, $this->getUser() ) ?
			SpecialPage::getTitleFor( 'History', $title )->getLocalURL() :
			$title->getLocalURL( [ 'action' => 'history' ] );
	}

	/**
	 * Prepare the content for the 'last edited' message, e.g. 'Last edited on 30 August
	 * 2013, at 23:31'. This message is different for the main page since main page
	 * content is typically transcluded rather than edited directly.
	 *
	 * The relative time is only rendered on the latest revision.
	 * For older revisions the last modified information will not render with a relative time
	 * nor will it show the name of the editor.
	 * @param Title $title The Title object of the page being viewed
	 * @return array|null
	 */
	protected function getHistoryLink( Title $title ) {
		$out = $this->getOutput();
		$isLatestRevision = $out->getRevisionId() === $title->getLatestRevID();
		// Get rev_timestamp of current revision (preloaded by MediaWiki core)
		$timestamp = $out->getRevisionTimestamp();
		# No cached timestamp, load it from the database
		if ( $timestamp === null ) {
			$timestamp = MediaWikiServices::getInstance()
				->getRevisionLookup()
				->getTimestampFromId( $out->getRevisionId() );
		}
		$historyIconClasses = [
			'historyIconClass' => MinervaUI::iconClass(
				'history-base20', 'mw-ui-icon-small', '', 'wikimedia'
			),
			'arrowIconClass' => MinervaUI::iconClass(
				'expand-gray', 'small',
				'mf-mw-ui-icon-rotate-anti-clockwise indicator',
				// Uses icon in MobileFrontend so must be prefixed mf.
				// Without MobileFrontend it will not render.
				// Rather than maintain 2 versions (and variants) of the arrow icon which can conflict
				// with each othe and bloat CSS, we'll
				// use the MobileFrontend one. Long term when T177432 and T160690 are resolved
				// we should be able to use one icon definition and break this dependency.
				'mf'
			),
		];
		$historyLink = !$isLatestRevision || $title->isMainPage() ?
			$this->getGenericHistoryLink( $title ) :
			$this->getRelativeHistoryLink( $title, $timestamp );

		return $this->canUseWikiPage() && $this->getWikiPage()->exists() ?
			$historyLink + $historyIconClasses : null;
	}

	/**
	 * Returns data attributes representing the editor for the current revision.
	 * @param LinkTarget $title The Title object of the page being viewed
	 * @return array representing user with name and gender fields. Empty if the editor no longer
	 *   exists in the database or is hidden from public view.
	 */
	private function getRevisionEditorData( LinkTarget $title ) {
		$rev = MediaWikiServices::getInstance()->getRevisionLookup()
			->getRevisionByTitle( $title );
		$result = [];
		if ( $rev ) {
			$revUser = $rev->getUser();
			// Note the user will only be returned if that information is public
			if ( $revUser ) {
				$revUser = User::newFromIdentity( $revUser );
				$editorName = $revUser->getName();
				$editorGender = $revUser->getOption( 'gender' );
				$result += [
					'data-user-name' => $editorName,
					'data-user-gender' => $editorGender,
				];
			}
		}
		return $result;
	}

	/**
	 * Returns the HTML representing the tagline
	 * @return string HTML for tagline
	 */
	protected function getTaglineHtml() {
		$tagline = '';

		if ( $this->getUserPageHelper()->isUserPage() ) {
			$pageUser = $this->getUserPageHelper()->getPageUser();
			$fromDate = $pageUser->getRegistration();

			if ( $this->getUserPageHelper()->isUserPageAccessibleToCurrentUser() && is_string( $fromDate ) ) {
				$fromDateTs = wfTimestamp( TS_UNIX, $fromDate );

				// This is shown when js is disabled. js enhancement made due to caching
				$tagline = $this->msg( 'mobile-frontend-user-page-member-since',
						$this->getLanguage()->userDate( new MWTimestamp( $fromDateTs ), $this->getUser() ),
						$pageUser )->text();

				// Define html attributes for usage with js enhancement (unix timestamp, gender)
				$attrs = [ 'id' => 'tagline-userpage',
					'data-userpage-registration-date' => $fromDateTs,
					'data-userpage-gender' => $pageUser->getOption( 'gender' ) ];
			}
		} else {
			$title = $this->getTitle();
			if ( $title ) {
				$out = $this->getOutput();
				$tagline = $out->getProperty( 'wgMFDescription' );
			}
		}

		$attrs[ 'class' ] = 'tagline';
		return Html::element( 'div', $attrs, $tagline );
	}

	/**
	 * Returns the HTML representing the heading.
	 * @return string HTML for header
	 */
	protected function getHeadingHtml() {
		$isUserPage = $this->getUserPageHelper()->isUserPage();
		$isUserPageAccessible = $this->getUserPageHelper()->isUserPageAccessibleToCurrentUser();
		if ( $isUserPage && $isUserPageAccessible ) {
			// The heading is just the username without namespace
			$heading = $this->getUserPageHelper()->getPageUser()->getName();
		} else {
			$heading = $this->getOutput()->getPageTitle();
		}
		return Html::rawElement( 'h1', [ 'id' => 'section_0' ], $heading );
	}

	/**
	 * @return bool Whether or not current title is a Talk page with the default
	 * action ('view')
	 */
	private function isTalkPageWithViewAction() {
		$title = $this->getTitle();

		// Hook is @unstable and only for use by DiscussionTools. Do not use for any other purpose.
		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
		if ( !$hookContainer->run( 'MinervaNeueTalkPageOverlay', [ $title, $this->getOutput() ] ) ) {
			return false;
		}

		return $title->isTalkPage() && Action::getActionName( $this->getContext() ) === "view";
	}

	/**
	 * @internal Should not be used outside Minerva.
	 * @todo Find better place for this.
	 *
	 * @return bool Whether or not the simplified talk page is enabled and action is 'view'
	 */
	public function isSimplifiedTalkPageEnabled(): bool {
		$title = $this->getTitle();
		$skinOptions = $this->getSkinOptions();

		return $this->isTalkPageWithViewAction() &&
			$skinOptions->get( SkinOptions::SIMPLIFIED_TALK ) &&
			// Only if viewing the latest revision, as we can't get the section numbers otherwise
			// (and even if we could, they would be useless, because edits often add and remove sections).
			$this->getOutput()->getRevisionId() === $title->getLatestRevID() &&
			$title->getContentModel() === CONTENT_MODEL_WIKITEXT;
	}

	/**
	 * Returns the postheadinghtml for the talk page with view action
	 *
	 * @return string HTML for postheadinghtml
	 */
	private function getTalkPagePostHeadingHtml() {
		$title = $this->getTitle();
		$html = '';

		// T237589: We don't want to show the add discussion button on Flow pages,
		// only wikitext pages
		if ( $this->getPermissions()->isTalkAllowed() &&
			$title->getContentModel() === CONTENT_MODEL_WIKITEXT
		) {
			$addTopicButton = $this->getTalkButton( $title, wfMessage(
				'minerva-talk-add-topic' )->text(), true );
			$html = Html::element( 'a', $addTopicButton['attributes'] + [
				'data-event-name' => 'talkpage.add-topic'
			], $addTopicButton['label'] );
		}

		if ( $this->isSimplifiedTalkPageEnabled() && $this->canUseWikiPage() ) {
			$wikiPage = $this->getWikiPage();
			$parserOptions = $wikiPage->makeParserOptions( $this->getContext() );
			$parserOutput = $wikiPage->getParserOutput( $parserOptions );
			$sectionCount = $parserOutput ? count( $parserOutput->getSections() ) : 0;

			$message = $sectionCount > 0 ? wfMessage( 'minerva-talk-explained' )
				: wfMessage( 'minerva-talk-explained-empty' );
			$html .= Html::element( 'div', [ 'class' =>
				'minerva-talk-content-explained' ], $message->text() );
		}

		return $html;
	}

	/**
	 * Create and prepare header and footer content
	 * @param BaseTemplate $tpl
	 */
	protected function prepareHeaderAndFooter( BaseTemplate $tpl ) {
		$title = $this->getTitle();
		$user = $this->getUser();
		$out = $this->getOutput();
		$tpl->set( 'taglinehtml', $this->getTaglineHtml() );

		if ( $title->isMainPage() ) {
			$pageTitle = '';
			$msg = $this->msg( 'mobile-frontend-logged-in-homepage-notification', $user->getName() );

			if ( $user->isRegistered() && !$msg->isDisabled() ) {
				$pageTitle = $msg->text();
			}

			$out->setPageTitle( $pageTitle );
		} elseif ( $this->isTalkPageWithViewAction() ) {
			// We only want the simplified talk page to show for the view action of the
			// talk (e.g. not history action)
			$tpl->set( 'postheadinghtml', $this->getTalkPagePostHeadingHtml() );
		}

		$tpl->set( 'headinghtml', $this->getHeadingHtml() );

		// set defaults
		if ( !isset( $tpl->data['postbodytext'] ) ) {
			$tpl->set( 'postbodytext', '' ); // not currently set in desktop skin
		}
	}

	/**
	 * Load internal banner content to show in pre content in template
	 * Beware of HTML caching when using this function.
	 * Content set as "internalbanner"
	 * @param BaseTemplate $tpl
	 */
	protected function prepareBanners( BaseTemplate $tpl ) {
		// Make sure Zero banner are always on top
		$banners = [ '<div id="siteNotice"></div>' ];
		if ( $this->getConfig()->get( 'MinervaEnableSiteNotice' ) ) {
			$siteNotice = $this->getSiteNotice();
			if ( $siteNotice ) {
				$banners[] = $siteNotice;
			}
		}
		$tpl->set( 'banners', $banners );
	}

	/**
	 * Returns an array with details for a language button.
	 * @return array
	 */
	protected function getLanguageButton() {
		$languageUrl = SpecialPage::getTitleFor(
			'MobileLanguages',
			$this->getSkin()->getTitle()
		)->getLocalURL();

		return [
			'attributes' => [
				'class' => 'language-selector',
				'href' => $languageUrl,
			],
			'label' => $this->msg( 'mobile-frontend-language-article-heading' )->text()
		];
	}

	/**
	 * Returns an array with details for a talk button.
	 * @param Title $talkTitle Title object of the talk page
	 * @param string $label Button label
	 * @param bool $addSection (optional) when added the talk button will render
	 *  as an add topic button. Defaults to false.
	 * @return array
	 */
	protected function getTalkButton( $talkTitle, $label, $addSection = false ) {
		if ( $addSection ) {
			$params = [ 'action' => 'edit', 'section' => 'new' ];
			$className = 'minerva-talk-add-button ' . MinervaUI::buttonClass( 'progressive', 'button' );
		} else {
			$params = [];
			$className = 'talk';
		}

		return [
			'attributes' => [
				'href' => $talkTitle->getLinkURL( $params ),
				'data-title' => $talkTitle->getFullText(),
				'class' => $className,
			],
			'label' => $label,
		];
	}

	/**
	 * Returns an array of links for page secondary actions
	 * @return array|null
	 */
	protected function getSecondaryActions() {
		if ( $this->isFallbackEditor() || !$this->hasSecondaryActions() ) {
			return null;
		}

		$services = MediaWikiServices::getInstance();
		$skinOptions = $this->getSkinOptions();
		$namespaceInfo = $services->getNamespaceInfo();
		/** @var \MediaWiki\Minerva\LanguagesHelper $languagesHelper */
		$languagesHelper = $services->getService( 'Minerva.LanguagesHelper' );
		$buttons = [];
		// always add a button to link to the talk page
		// it will link to the wikitext talk page
		$title = $this->getTitle();
		$subjectPage = Title::newFromLinkTarget( $namespaceInfo->getSubjectPage( $title ) );
		$talkAtBottom = !$skinOptions->get( SkinOptions::TALK_AT_TOP ) ||
			$subjectPage->isMainPage();
		if ( !$this->getUserPageHelper()->isUserPage() &&
			$this->getPermissions()->isTalkAllowed() && $talkAtBottom &&
			// When showing talk at the bottom we restrict this so it is not shown to anons
			// https://phabricator.wikimedia.org/T54165
			// This whole code block can be removed when SkinOptions::TALK_AT_TOP is always true
			$this->getUser()->isRegistered() &&
			!$this->isTalkPageWithViewAction()
		) {
			$namespaces = $this->contentNavigationUrls['namespaces'];
			// FIXME [core]: This seems unnecessary..
			$subjectId = $title->getNamespaceKey( '' );
			$talkId = $subjectId === 'main' ? 'talk' : "{$subjectId}_talk";

			if ( isset( $namespaces[$talkId] ) ) {
				$talkButton = $namespaces[$talkId];
				$talkTitle = Title::newFromLinkTarget( $namespaceInfo->getTalkPage( $title ) );

				$buttons['talk'] = $this->getTalkButton( $talkTitle, $talkButton['text'] );
			}
		}

		if ( $languagesHelper->doesTitleHasLanguagesOrVariants( $title ) && $title->isMainPage() ) {
			$buttons['language'] = $this->getLanguageButton();
		}

		return $buttons;
	}

	/**
	 * Minerva skin do not have sidebar, there is no need to calculate that.
	 * @return array
	 */
	public function buildSidebar() {
		return [];
	}

	/**
	 * @inheritDoc
	 * @return array
	 */
	protected function getJsConfigVars(): array {
		$title = $this->getTitle();
		$skinOptions = $this->getSkinOptions();

		return array_merge( parent::getJsConfigVars(), [
			'wgMinervaPermissions' => [
				'watch' => $this->getPermissions()->isAllowed( IMinervaPagePermissions::WATCH ),
				'talk' => $this->getUserPageHelper()->isUserPage() ||
					( $this->getPermissions()->isTalkAllowed() || $title->isTalkPage() ) &&
					$this->isWikiTextTalkPage()
			],
			'wgMinervaFeatures' => $skinOptions->getAll(),
			'wgMinervaDownloadNamespaces' => $this->getConfig()->get( 'MinervaDownloadNamespaces' ),
		] );
	}

	/**
	 * Returns true, if the talk page of this page is wikitext-based.
	 * @return bool
	 */
	protected function isWikiTextTalkPage() {
		$title = $this->getTitle();
		if ( !$title->isTalkPage() ) {
			$namespaceInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
			$title = Title::newFromLinkTarget( $namespaceInfo->getTalkPage( $title ) );
		}
		return $title->isWikitextPage();
	}

	/**
	 * Returns the javascript entry modules to load. Only modules that need to
	 * be overriden or added conditionally should be placed here.
	 * @return array
	 */
	public function getDefaultModules() {
		$modules = parent::getDefaultModules();

		// FIXME: T223204: Dequeue default content modules except for the history
		// action. Allow default history action content modules
		// in order to enable toggling of the
		// filters. Long term this won't be necessary when T111565 is resolved and a
		// more general solution can be used.
		if ( Action::getActionName( $this->getContext() ) !== 'history' ) {
			// dequeue default content modules (toc, sortable, collapsible, etc.)
			$modules['content'] = array_diff( $modules['content'], [
				// T233340
				'jquery.tablesorter',
				// T111565
				'jquery.makeCollapsible',
				// Minerva provides its own implementation. Loading this will break display.
				'mediawiki.toc'
			] );
			// dequeue styles associated with `content` key.
			$modules['styles']['content'] = array_diff( $modules['styles']['content'], [
				// T233340
				'jquery.tablesorter.styles',
				// T111565
				'jquery.makeCollapsible.styles',
			] );
		}
		$modules['styles']['core'] = $this->getSkinStyles();

		$modules['minerva'] = [
			'skins.minerva.scripts'
		];

		return $modules;
	}

	/**
	 * Provide styles required to present the server rendered page in this skin. Additional styles
	 * may be loaded dynamically by the client.
	 *
	 * Any styles returned by this method are loaded on the critical rendering path as linked
	 * stylesheets. I.e., they are required to load on the client before first paint.
	 *
	 * @return array
	 */
	protected function getSkinStyles(): array {
		$title = $this->getTitle();
		$skinOptions = $this->getSkinOptions();
		$styles = [
			'skins.minerva.base.styles',
			'skins.minerva.content.styles.images',
			'mediawiki.hlist',
			'mediawiki.ui.icon',
			'mediawiki.ui.button',
			'skins.minerva.icons.wikimedia',
			'skins.minerva.mainMenu.icons',
			'skins.minerva.mainMenu.styles',
		];
		if ( $title->isMainPage() ) {
			$styles[] = 'skins.minerva.mainPage.styles';
		} elseif ( $this->getUserPageHelper()->isUserPage() ) {
			$styles[] = 'skins.minerva.userpage.styles';
		} elseif ( $this->isTalkPageWithViewAction() ) {
			$styles[] = 'skins.minerva.talk.styles';
		}

		if ( $this->hasCategoryLinks() ) {
			$styles[] = 'skins.minerva.categories.styles';
		}

		if ( $this->getUser()->isRegistered() ) {
			$styles[] = 'skins.minerva.loggedin.styles';
			$styles[] = 'skins.minerva.icons.loggedin';
		}

		// When any of these features are enabled in production
		// remove the if condition
		// and move the associated LESS file inside `skins.minerva.amc.styles`
		// into a more appropriate module.
		if (
			$skinOptions->get( SkinOptions::PERSONAL_MENU ) ||
			$skinOptions->get( SkinOptions::TALK_AT_TOP ) ||
			$skinOptions->get( SkinOptions::HISTORY_IN_PAGE_ACTIONS ) ||
			$skinOptions->get( SkinOptions::TOOLBAR_SUBMENU )
		) {
			// SkinOptions::PERSONAL_MENU + SkinOptions::TOOLBAR_SUBMENU uses ToggleList
			// SkinOptions::TALK_AT_TOP uses tabs.less
			// SkinOptions::HISTORY_IN_PAGE_ACTIONS + SkinOptions::TOOLBAR_SUBMENU uses pageactions.less
			$styles[] = 'skins.minerva.amc.styles';
		}

		if ( $skinOptions->get( SkinOptions::PERSONAL_MENU ) ) {
			// If ever enabled as the default, please remove the duplicate icons
			// inside skins.minerva.mainMenu.icons. See comment for MAIN_MENU_EXPANDED
			$styles[] = 'skins.minerva.personalMenu.icons';
		}

		if (
			$skinOptions->get( SkinOptions::MAIN_MENU_EXPANDED )
		) {
			// If ever enabled as the default, please review skins.minerva.mainMenu.icons
			// and remove any unneeded icons
			$styles[] = 'skins.minerva.mainMenu.advanced.icons';
		}
		if (
			$skinOptions->get( SkinOptions::PERSONAL_MENU ) ||
			$skinOptions->get( SkinOptions::TOOLBAR_SUBMENU )
		) {
			// SkinOptions::PERSONAL_MENU requires the `userTalk` icon.
			// SkinOptions::TOOLBAR_SUBMENU requires the rest of the icons including `overflow`.
			// Note `skins.minerva.overflow.icons` is pulled down by skins.minerva.scripts but the menu can
			// work without JS.
			$styles[] = 'skins.minerva.overflow.icons';
		}

		return $styles;
	}
}

// Setup alias for compatibility with SkinMinervaNeue.
class_alias( 'SkinMinerva', 'SkinMinervaNeue' );
