<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV;

use OC\L10N\L10N;
use OCA\DAV\CalDAV\BirthdayService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\DAV\CardDAV\SyncService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Util;

class HookManager {

	/** @var IUserManager */
	private $userManager;

	/** @var SyncService */
	private $syncService;

	/** @var IUser[] */
	private $usersToDelete;

	/** @var array */
	private $calendarsToDelete;

	/** @var array */
	private $addressBooksToDelete;

	/** @var CalDavBackend */
	private $calDav;

	/** @var CardDavBackend */
	private $cardDav;

	/** @var L10N */
	private $l10n;

	public function __construct(IUserManager $userManager,
								SyncService $syncService,
								CalDavBackend $calDav,
								CardDavBackend $cardDav,
								L10N $l10n) {
		$this->userManager = $userManager;
		$this->syncService = $syncService;
		$this->calDav = $calDav;
		$this->cardDav = $cardDav;
		$this->l10n = $l10n;
	}

	public function setup() {
		Util::connectHook('OC_User',
			'post_createUser',
			$this,
			'postCreateUser');
		Util::connectHook('OC_User',
			'pre_deleteUser',
			$this,
			'preDeleteUser');
		Util::connectHook('OC_User',
			'post_deleteUser',
			$this,
			'postDeleteUser');
		Util::connectHook('OC_User',
			'changeUser',
			$this,
			'changeUser');
		Util::connectHook('OC_User',
			'post_login',
			$this,
			'postLogin');
	}

	public function postCreateUser($params) {
		$user = $this->userManager->get($params['uid']);
		$this->syncService->updateUser($user);
	}

	public function preDeleteUser($params) {
		$user = $this->userManager->get($params['uid']);

		$this->usersToDelete[$params['uid']] = $user;

		$this->calendarsToDelete = $this->calDav->getCalendarsForUser('principals/users/' . $user->getUID());
		$this->addressBooksToDelete = $this->cardDav->getAddressBooksForUser('principals/users/' . $user->getUID());
	}

	public function postDeleteUser($params) {
		$uid = $params['uid'];
		if (isset($this->usersToDelete[$uid])){
			$this->syncService->deleteUser($this->usersToDelete[$uid]);
		}
		if (!is_null($this->calendarsToDelete)) {
			foreach ($this->calendarsToDelete as $calendar) {
				$this->calDav->deleteCalendar($calendar['id']);
			}
		}
		if (!is_null($this->addressBooksToDelete)) {
			foreach ($this->addressBooksToDelete as $addressBook) {
				$this->cardDav->deleteAddressBook($addressBook['id']);
			}
		}
	}

	public function changeUser($params) {
		$user = $params['user'];
		$this->syncService->updateUser($user);
	}

	public function postLogin($params) {
		$user = $this->userManager->get($params['uid']);
		if (!is_null($user)) {
			$principal = 'principals/users/' . $user->getUID();
			$calendars = $this->calDav->getCalendarsForUser($principal);
			if (empty($calendars) || (count($calendars) === 1 && $calendars[0]['uri'] === BirthdayService::BIRTHDAY_CALENDAR_URI)) {
				try {
					$this->calDav->createCalendar($principal, 'personal', [
						'{DAV:}displayname' => $this->l10n->t('Personal'),
						'{http://apple.com/ns/ical/}calendar-color' => '#1d2d44']);
				} catch (\Exception $ex) {
					\OC::$server->getLogger()->logException($ex);
				}
			}
			$books = $this->cardDav->getAddressBooksForUser($principal);
			if (empty($books)) {
				try {
					$this->cardDav->createAddressBook($principal, 'contacts', [
						'{DAV:}displayname' => $this->l10n->t('Contacts')]);
				} catch (\Exception $ex) {
					\OC::$server->getLogger()->logException($ex);
				}
			}
		}
	}
}
