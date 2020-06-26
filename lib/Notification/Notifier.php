<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Spreed\Notification;


use OCA\Spreed\Chat\CommentsManager;
use OCA\Spreed\Chat\MessageParser;
use OCA\Spreed\Config;
use OCA\Spreed\Exceptions\ParticipantNotFoundException;
use OCA\Spreed\Exceptions\RoomNotFoundException;
use OCA\Spreed\GuestManager;
use OCA\Spreed\Manager;
use OCA\Spreed\Participant;
use OCA\Spreed\Room;
use OCP\Comments\NotFoundException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\RichObjectStrings\Definitions;
use OCP\Share;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;

class Notifier implements INotifier {

	/** @var IFactory */
	protected $lFactory;
	/** @var IURLGenerator */
	protected $url;
	/** @var Config */
	protected $config;
	/** @var IUserManager */
	protected $userManager;
	/** @var GuestManager */
	protected $guestManager;
	/** @var IShareManager */
	private $shareManager;
	/** @var Manager */
	protected $manager;
	/** @var INotificationManager */
	protected $notificationManager;
	/** @var CommentsManager */
	protected $commentManager;
	/** @var MessageParser */
	protected $messageParser;
	/** @var Definitions */
	protected $definitions;

	public function __construct(IFactory $lFactory,
								IURLGenerator $url,
								Config $config,
								IUserManager $userManager,
								GuestManager $guestManager,
								IShareManager $shareManager,
								Manager $manager,
								INotificationManager $notificationManager,
								CommentsManager $commentManager,
								MessageParser $messageParser,
								Definitions $definitions) {
		$this->lFactory = $lFactory;
		$this->url = $url;
		$this->config = $config;
		$this->userManager = $userManager;
		$this->guestManager = $guestManager;
		$this->shareManager = $shareManager;
		$this->manager = $manager;
		$this->notificationManager = $notificationManager;
		$this->commentManager = $commentManager;
		$this->messageParser = $messageParser;
		$this->definitions = $definitions;
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getID(): string {
		return 'talk';
	}

	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getName(): string {
		return $this->lFactory->get('spreed')->t('Talk');
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 * @return INotification
	 * @throws \InvalidArgumentException When the notification was not prepared by a notifier
	 * @since 9.0.0
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'spreed') {
			throw new \InvalidArgumentException('Incorrect app');
		}

		$userId = $notification->getUser();
		$user = $this->userManager->get($userId);
		if (!$user instanceof IUser || $this->config->isDisabledForUser($user)) {
			throw new AlreadyProcessedException();
		}

		$l = $this->lFactory->get('spreed', $languageCode);

		try {
			$room = $this->manager->getRoomByToken($notification->getObjectId());
		} catch (RoomNotFoundException $e) {
			try {
				// Before 3.2.3 the id was passed in notifications
				$room = $this->manager->getRoomById((int) $notification->getObjectId());
			} catch (RoomNotFoundException $e) {
				// Room does not exist
				throw new AlreadyProcessedException();
			}
		}

		try {
			$participant = $room->getParticipant($userId);
		} catch (ParticipantNotFoundException $e) {
			// Room does not exist
			throw new AlreadyProcessedException();
		}

		$notification
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath('spreed', 'app-dark.svg')))
			->setLink($this->url->linkToRouteAbsolute('spreed.pagecontroller.showCall', ['token' => $room->getToken()]));

		$subject = $notification->getSubject();
		if ($subject === 'invitation') {
			return $this->parseInvitation($notification, $room, $l);
		}
		if ($subject === 'call') {
			if ($room->getObjectType() === 'share:password') {
				return $this->parsePasswordRequest($notification, $room, $l);
			}
			return $this->parseCall($notification, $room, $l);
		}
		if ($subject === 'reply' || $subject === 'mention' || $subject === 'chat') {
			return $this->parseChatMessage($notification, $room, $participant, $l);
		}

		$this->notificationManager->markProcessed($notification);
		throw new \InvalidArgumentException('Unknown subject');
	}

	protected function shortenJsonEncodedMultibyteSave(string $subject, int $dataLength): string {
		$temp = mb_substr($subject, 0, $dataLength);
		while (strlen(json_encode($temp)) > $dataLength) {
			$temp = mb_substr($temp, 0, -5);
		}
		return $temp;
	}

	/**
	 * @param INotification $notification
	 * @param Room $room
	 * @param Participant $participant
	 * @param IL10N $l
	 * @return INotification
	 * @throws \InvalidArgumentException
	 */
	protected function parseChatMessage(INotification $notification, Room $room, Participant $participant, IL10N $l): INotification {
		if ($notification->getObjectType() !== 'chat') {
			throw new \InvalidArgumentException('Unknown object type');
		}

		$subjectParameters = $notification->getSubjectParameters();

		$richSubjectUser = null;
		$isGuest = false;
		if ($subjectParameters['userType'] === 'users') {
			$userId = $subjectParameters['userId'];
			$user = $this->userManager->get($userId);

			if ($user instanceof IUser) {
				$richSubjectUser = [
					'type' => 'user',
					'id' => $userId,
					'name' => $user->getDisplayName(),
				];
			}
		} else {
			$isGuest = true;
		}

		$richSubjectCall = [
			'type' => 'call',
			'id' => $room->getId(),
			'name' => $room->getDisplayName($notification->getUser()),
			'call-type' => $this->getRoomType($room),
		];

		$messageParameters = $notification->getMessageParameters();
		if (!isset($messageParameters['commentId'])) {
			throw new AlreadyProcessedException();
		}

		try {
			$comment = $this->commentManager->get($messageParameters['commentId']);
		} catch (NotFoundException $e) {
			throw new AlreadyProcessedException();
		}

		$message = $this->messageParser->createMessage($room, $participant, $comment, $l);
		$this->messageParser->parseMessage($message);

		if (!$message->getVisibility()) {
			throw new AlreadyProcessedException();
		}

		$placeholders = $replacements = [];
		foreach ($message->getMessageParameters() as $placeholder => $parameter) {
			$placeholders[] = '{' . $placeholder . '}';
			if ($parameter['type'] === 'user' || $parameter['type'] === 'guest') {
				$replacements[] = '@' . $parameter['name'];
			} else {
				$replacements[] = $parameter['name'];
			}
		}

		$parsedMessage = str_replace($placeholders, $replacements, $message->getMessage());
		if (!$this->notificationManager->isPreparingPushNotification()) {
			$notification->setParsedMessage($parsedMessage);
			$notification->setRichMessage($message->getMessage(), $message->getMessageParameters());
		}

		$richSubjectParameters = [
			'user' => $richSubjectUser,
			'call' => $richSubjectCall,
		];

		if ($this->notificationManager->isPreparingPushNotification()) {
			$shortenMessage = $this->shortenJsonEncodedMultibyteSave($parsedMessage, 100);
			if ($shortenMessage !== $parsedMessage) {
				$shortenMessage .= '…';
			}
			$richSubjectParameters['message'] = [
				'type' => 'highlight',
				'id' => $message->getComment()->getId(),
				'name' => $shortenMessage,
			];
			if ($room->getType() === Room::ONE_TO_ONE_CALL) {
				$subject = $l->t('{user}: {message}');
			} elseif ($richSubjectUser) {
				$subject = $l->t('{user} in {call}: {message}');
			} elseif (!$isGuest) {
				$subject = $l->t('Deleted user in {call}: {message}');
			} else {
				try {
					$richSubjectParameters['guest'] = $this->getGuestParameter($comment->getActorId());
					$subject = $l->t('{guest} (guest) in {call}: {message}');
				} catch (ParticipantNotFoundException $e) {
					$subject = $l->t('Guest in {call}: {message}');
				}
			}
		} elseif ($notification->getSubject() === 'chat') {
			if ($room->getType() === Room::ONE_TO_ONE_CALL) {
				$subject = $l->t('{user} sent you a private message');
			} else if ($richSubjectUser) {
				$subject = $l->t('{user} sent a message in conversation {call}');
			} else if (!$isGuest) {
				$subject = $l->t('A deleted user sent a message in conversation {call}');
			} else {
				try {
					$richSubjectParameters['guest'] = $this->getGuestParameter($comment->getActorId());
					$subject = $l->t('{guest} (guest) sent a message in conversation {call}');
				} catch (ParticipantNotFoundException $e) {
					$subject = $l->t('A guest sent a message in conversation {call}');
				}
			}
		} else if ($notification->getSubject() === 'reply') {
			if ($room->getType() === Room::ONE_TO_ONE_CALL) {
				$subject = $l->t('{user} replied to your private message');
			} else if ($richSubjectUser) {
				$subject = $l->t('{user} replied to your message in conversation {call}');
			} else if (!$isGuest) {
				$subject = $l->t('A deleted user replied to your message in conversation {call}');
			} else {
				try {
					$richSubjectParameters['guest'] = $this->getGuestParameter($comment->getActorId());
					$subject = $l->t('{guest} (guest) replied to your message in conversation {call}');
				} catch (ParticipantNotFoundException $e) {
					$subject = $l->t('A guest replied to your message in conversation {call}');
				}
			}
		} else if ($room->getType() === Room::ONE_TO_ONE_CALL) {
			$subject = $l->t('{user} mentioned you in a private conversation');
		} else if ($richSubjectUser) {
			$subject = $l->t('{user} mentioned you in conversation {call}');
		} else if (!$isGuest) {
			$subject = $l->t('A deleted user mentioned you in conversation {call}');
		} else {
			try {
				$richSubjectParameters['guest'] = $this->getGuestParameter($comment->getActorId());
				$subject = $l->t('{guest} (guest) mentioned you in conversation {call}');
			} catch (ParticipantNotFoundException $e) {
				$subject = $l->t('A guest mentioned you in conversation {call}');
			}
		}
		$notification = $this->addActionButton($notification, $l->t('View chat'), false);

		if ($richSubjectParameters['user'] === null) {
			unset($richSubjectParameters['user']);
		}

		$placeholders = $replacements = [];
		foreach ($richSubjectParameters as $placeholder => $parameter) {
			$placeholders[] = '{' . $placeholder . '}';
			$replacements[] = $parameter['name'];
		}

		$notification->setParsedSubject(str_replace($placeholders, $replacements, $subject))
			->setRichSubject($subject, $richSubjectParameters);

		return $notification;
	}

	/**
	 * @param string $actorId
	 * @return array
	 * @throws ParticipantNotFoundException
	 */
	protected function getGuestParameter(string $actorId): array {
		$name = $this->guestManager->getNameBySessionHash($actorId);
		if (trim($name) === '') {
			throw new ParticipantNotFoundException('Empty name');
		}

		return [
			'type' => 'guest',
			'id' => $actorId,
			'name' => $name,
		];
	}

	/**
	 * @param Room $room
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	protected function getRoomType(Room $room): string {
		switch ($room->getType()) {
			case Room::ONE_TO_ONE_CALL:
				return 'one2one';
			case Room::GROUP_CALL:
				return 'group';
			case Room::PUBLIC_CALL:
				return 'public';
			default:
				throw new \InvalidArgumentException('Unknown room type');
		}
	}

	/**
	 * @param INotification $notification
	 * @param Room $room
	 * @param IL10N $l
	 * @return INotification
	 * @throws \InvalidArgumentException
	 * @throws AlreadyProcessedException
	 */
	protected function parseInvitation(INotification $notification, Room $room, IL10N $l): INotification {
		if ($notification->getObjectType() !== 'room') {
			throw new \InvalidArgumentException('Unknown object type');
		}

		$parameters = $notification->getSubjectParameters();
		$uid = $parameters['actorId'] ?? $parameters[0];

		$user = $this->userManager->get($uid);
		if (!$user instanceof IUser) {
			throw new AlreadyProcessedException();
		}

		$roomName = $room->getDisplayName($notification->getUser());
		if ($room->getType() === Room::ONE_TO_ONE_CALL) {
			$subject = $l->t('{user} invited you to a private conversation');
			if ($room->hasSessionsInCall()) {
				$notification = $this->addActionButton($notification, $l->t('Join call'));
			} else {
				$notification = $this->addActionButton($notification, $l->t('View chat'), false);
			}

			$notification
				->setParsedSubject(str_replace('{user}', $user->getDisplayName(), $subject))
				->setRichSubject(
					$subject, [
						'user' => [
							'type' => 'user',
							'id' => $uid,
							'name' => $user->getDisplayName(),
						],
						'call' => [
							'type' => 'call',
							'id' => $room->getId(),
							'name' => $roomName,
							'call-type' => $this->getRoomType($room),
						],
					]
				);

		} else if (\in_array($room->getType(), [Room::GROUP_CALL, Room::PUBLIC_CALL], true)) {
			$subject = $l->t('{user} invited you to a group conversation: {call}');
			if ($room->hasSessionsInCall()) {
				$notification = $this->addActionButton($notification, $l->t('Join call'));
			} else {
				$notification = $this->addActionButton($notification, $l->t('View chat'), false);
			}

			$notification
				->setParsedSubject(str_replace(['{user}', '{call}'], [$user->getDisplayName(), $roomName], $subject))
				->setRichSubject(
					$subject, [
						'user' => [
							'type' => 'user',
							'id' => $uid,
							'name' => $user->getDisplayName(),
						],
						'call' => [
							'type' => 'call',
							'id' => $room->getId(),
							'name' => $roomName,
							'call-type' => $this->getRoomType($room),
						],
					]
				);
		} else {
			throw new AlreadyProcessedException();
		}

		return $notification;
	}

	/**
	 * @param INotification $notification
	 * @param Room $room
	 * @param IL10N $l
	 * @return INotification
	 * @throws \InvalidArgumentException
	 * @throws AlreadyProcessedException
	 */
	protected function parseCall(INotification $notification, Room $room, IL10N $l): INotification {
		if ($notification->getObjectType() !== 'call') {
			throw new \InvalidArgumentException('Unknown object type');
		}

		$roomName = $room->getDisplayName($notification->getUser());
		if ($room->getType() === Room::ONE_TO_ONE_CALL) {
			$parameters = $notification->getSubjectParameters();
			$calleeId = $parameters['callee'];
			$user = $this->userManager->get($calleeId);
			if ($user instanceof IUser) {
				if ($this->notificationManager->isPreparingPushNotification() || $room->hasSessionsInCall()) {
					$notification = $this->addActionButton($notification, $l->t('Answer call'));
					$subject = $l->t('{user} wants to talk with you');
				} else {
					$notification = $this->addActionButton($notification, $l->t('Call back'));
					$subject = $l->t('You missed a call from {user}');
				}

				$notification
					->setParsedSubject(str_replace('{user}', $user->getDisplayName(), $subject))
					->setRichSubject(
						$subject, [
							'user' => [
								'type' => 'user',
								'id' => $calleeId,
								'name' => $user->getDisplayName(),
							],
							'call' => [
								'type' => 'call',
								'id' => $room->getId(),
								'name' => $roomName,
								'call-type' => $this->getRoomType($room),
							],
						]
					);
			} else {
				throw new AlreadyProcessedException();
			}

		} else if (\in_array($room->getType(), [Room::GROUP_CALL, Room::PUBLIC_CALL], true)) {
			if ($this->notificationManager->isPreparingPushNotification() || $room->hasSessionsInCall()) {
				$notification = $this->addActionButton($notification, $l->t('Join call'));
				$subject = $l->t('A group call has started in {call}');
			} else {
				$notification = $this->addActionButton($notification, $l->t('View chat'), false);
				$subject = $l->t('You missed a group call in {call}');
			}

			$notification
				->setParsedSubject(str_replace('{call}', $roomName, $subject))
				->setRichSubject(
					$subject, [
						'call' => [
							'type' => 'call',
							'id' => $room->getId(),
							'name' => $roomName,
							'call-type' => $this->getRoomType($room),
						],
					]
				);

		} else {
			throw new AlreadyProcessedException();
		}

		return $notification;
	}

	/**
	 * @param INotification $notification
	 * @param Room $room
	 * @param IL10N $l
	 * @return INotification
	 * @throws \InvalidArgumentException
	 * @throws AlreadyProcessedException
	 */
	protected function parsePasswordRequest(INotification $notification, Room $room, IL10N $l): INotification {
		if ($notification->getObjectType() !== 'call') {
			throw new \InvalidArgumentException('Unknown object type');
		}

		try {
			$share = $this->shareManager->getShareByToken($room->getObjectId());
		} catch (ShareNotFound $e) {
			throw new AlreadyProcessedException();
		}

		try {
			$file = [
				'type' => 'highlight',
				'id' => $share->getNodeId(),
				'name' => $share->getNode()->getName(),
			];
		} catch (\OCP\Files\NotFoundException $e) {
			throw new AlreadyProcessedException();
		}

		$callIsActive = $this->notificationManager->isPreparingPushNotification() || $room->hasSessionsInCall();
		if ($callIsActive) {
			$notification = $this->addActionButton($notification, $l->t('Answer call'));
		} else {
			$notification = $this->addActionButton($notification, $l->t('Call back'));
		}

		if ($share->getShareType() === Share::SHARE_TYPE_EMAIL) {
			$sharedWith = $share->getSharedWith();
			if ($callIsActive) {
				$subject = $l->t('{email} is requesting the password to access {file}');
			} else {
				$subject = $l->t('{email} tried to requested the password to access {file}');
			}

			$notification
				->setParsedSubject(str_replace(['{email}', '{file}'], [$sharedWith, $file['name']], $subject))
				->setRichSubject($subject, [
						'email' => [
							'type' => 'email',
							'id' => $sharedWith,
							'name' => $sharedWith,
						],
						'file' => $file,
					]
				);
		} else {
			if ($callIsActive) {
				$subject = $l->t('Someone is requesting the password to access {file}');
			} else {
				$subject = $l->t('Someone tried to requested the password to access {file}');
			}

			$notification
				->setParsedSubject(str_replace('{file}', $file['name'], $subject))
				->setRichSubject($subject, ['file' => $file]);
		}

		return $notification;
	}

	protected function addActionButton(INotification $notification, string $label, bool $primary = true): INotification {
		$action = $notification->createAction();
		$action->setLabel($label)
			->setParsedLabel($label)
			->setLink($notification->getLink(), IAction::TYPE_WEB)
			->setPrimary($primary);

		$notification->addParsedAction($action);

		return $notification;
	}
}
