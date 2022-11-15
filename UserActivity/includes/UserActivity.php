<?php
/**
 * UserActivity class
 */
class UserActivity {

	/**
	 * All member variables should be considered private
	 * Please use the accessor functions
	 */

	/** @var User|null */
	private $user;
	/** @var array[]|null */
	private $items;
	/** @var int|null */
	private $rel_type;
	/** @var bool */
	private $show_current_user = false;
	/** @var int */
	private $show_edits = 1;
	/** @var int */
	private $show_messages_sent = 1;
	/** @var bool */
	private $show_all;
	/** @var int */
	private $item_max;
	/** @var int */
	private $now;
	/** @var int */
	private $three_days_ago;
	/** @var array */
	private $items_grouped;
	/** @var array[]|null */
	private $displayed;
	/** @var array[]|null */
	private $activityLines;

	/**
	 * @param User|null $user User object whose activity feed we want
	 * @param string $filter Passed to setFilter(); can be either
	 * 'user', 'friends', 'foes' or 'all', depending on what
	 * kind of information is wanted
	 * @param int $item_max Maximum amount of items to display in the feed
	 */
	public function __construct( $user, $filter, $item_max ) {
		if ( $user ) {
			$this->user = $user;
		}
		$this->setFilter( $filter );
		$this->item_max = $item_max;
		$this->now = time();
		$this->three_days_ago = $this->now - ( 60 * 60 * 24 * 3 );
		$this->items_grouped = [];
	}

	private function setFilter( $filter ) {
		if ( strtoupper( $filter ) == 'USER' ) {
			$this->show_current_user = true;
		}
		if ( strtoupper( $filter ) == 'FRIENDS' ) {
			$this->rel_type = 1;
		}
		if ( strtoupper( $filter ) == 'ALL' ) {
			$this->show_all = true;
		}
	}

	/**
	 * Sets the value of class member variable $name to $value.
	 *
	 * @param string $name
	 * @param mixed $value
	 */
	public function setActivityToggle( $name, $value ) {
		$this->$name = $value;
	}

	/**
	 * Get recent edits from the recentchanges table and set them in the
	 * appropriate class member variables.
	 */
	private function setEdits() {
		$dbr = wfGetDB( DB_REPLICA );

		$where = [];

		if ( !empty( $this->rel_type ) ) {
			$users = $dbr->select(
				'user_relationship',
				'r_actor_relation',
				[
					'r_actor' => $this->user->getActorId(),
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$actorIDs = implode( ',', $userArray );
			if ( !empty( $actorIDs ) ) {
				$where[] = "actor_id IN ($actorIDs)";
			}
		}

		if ( !empty( $this->show_current_user ) ) {
			$where['actor_id'] = $this->user->getActorId();
		}
		$actorQuery = ActorMigration::newMigration()->getJoin( 'rc_user' ); // @todo This usage is deprecated since MW 1.34.

		$commentStore = CommentStore::getStore();
		$commentQuery = $commentStore->getJoin( 'rc_comment' );

		// @phan-suppress-next-line SecurityCheck-SQLInjection The escaping here is totally proper, phan just can't tell
		$res = $dbr->select(
			[ 'recentchanges' ] + $commentQuery['tables'] + $actorQuery['tables'],
			[
				'rc_timestamp', 'rc_title',
				'rc_id', 'rc_minor',
				'rc_source', 'rc_namespace', 'rc_cur_id', 'rc_this_oldid',
				'rc_last_oldid', 'rc_log_action'
			] + $commentQuery['fields'] + $actorQuery['fields'],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'rc_id DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			],
			$commentQuery['joins'] + $actorQuery['joins']
		);

		foreach ( $res as $row ) {
			// Special pages aren't editable, so ignore them
			// And blocking a vandal should not be counted as editing said
			// vandal's user page...
			if ( $row->rc_namespace == NS_SPECIAL || $row->rc_log_action != null ) {
				continue;
			}

			$title = Title::makeTitle( $row->rc_namespace, $row->rc_title );
			$unixTS = wfTimestamp( TS_UNIX, $row->rc_timestamp );

			$this->items_grouped['edit'][$title->getPrefixedText()]['users'][$row->rc_user_text][] = [
				'id' => 0,
				'type' => 'edit',
				'timestamp' => $unixTS,
				'pagetitle' => $row->rc_title,
				'namespace' => $row->rc_namespace,
				'username' => $row->rc_user_text,
				'comment' => $this->fixItemComment( $commentStore->getComment(
					'rc_comment', $row )->text ),
				'minor' => $row->rc_minor,
				'new' => $row->rc_source === RecentChange::SRC_NEW
			];

			// set last timestamp
			$this->items_grouped['edit'][$title->getPrefixedText()]['timestamp'] = $unixTS;

			$this->items[] = [
				'id' => 0,
				'type' => 'edit',
				'timestamp' => $unixTS,
				'pagetitle' => $row->rc_title,
				'namespace' => $row->rc_namespace,
				'username' => $row->rc_user_text,
				'comment' => $this->fixItemComment( $commentStore->getComment(
					'rc_comment', $row )->text ),
				'minor' => $row->rc_minor,
				'new' => $row->rc_source === RecentChange::SRC_NEW
			];
		}
	}

	/**
	 * Get recently sent public user board messages from the user_board table
	 * and set them in the appropriate class member variables.
	 */
	private function setMessagesSent() {
		$dbr = wfGetDB( DB_REPLICA );

		$where = [];
		// We do *not* want to display private messages...
		$where['ub_type'] = UserBoard::MESSAGE_PUBLIC;

		if ( !empty( $this->rel_type ) ) {
			$users = $dbr->select(
				'user_relationship',
				'r_actor_relation',
				[
					'r_actor' => $this->user->getActorId(),
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$actorIDs = implode( ',', $userArray );
			if ( !empty( $actorIDs ) ) {
				$where[] = "ub_actor_from IN ($actorIDs)";
			}
		}

		if ( !empty( $this->show_current_user ) ) {
			$where['ub_actor_from'] = $this->user->getActorId();
		}

		// @phan-suppress-next-line SecurityCheck-SQLInjection The escaping here is totally proper, phan just can't tell
		$res = $dbr->select(
			'user_board',
			[ 'ub_id', 'ub_actor', 'ub_actor_from', 'ub_date', 'ub_message' ],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'ub_id DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			]
		);

		foreach ( $res as $row ) {
			// Ignore nonexistent (for example, renamed) users
			$user = User::newFromActorId( $row->ub_actor );
			if ( !$user ) {
				continue;
			}

			$to = $user->getName();
			$from = User::newFromActorId( $row->ub_actor_from )->getName();
			$unixTS = wfTimestamp( TS_UNIX, $row->ub_date );

			$this->items_grouped['user_message'][$to]['users'][$from][] = [
				'id' => $row->ub_id,
				'type' => 'user_message',
				'timestamp' => $unixTS,
				'pagetitle' => '',
				'namespace' => '',
				'username' => $from,
				'comment' => $to,
				'minor' => 0,
				'new' => 0
			];

			// set last timestamp
			$this->items_grouped['user_message'][$to]['timestamp'] = $unixTS;

			$this->items[] = [
				'id' => $row->ub_id,
				'type' => 'user_message',
				'timestamp' => $unixTS,
				'pagetitle' => '',
				'namespace' => $this->fixItemComment( $row->ub_message ),
				'username' => $from,
				'comment' => $to,
				'new' => '0',
				'minor' => 0
			];
		}
	}

	public function getEdits() {
		$this->setEdits();
		return $this->items;
	}

	public function getMessagesSent() {
		$this->setMessagesSent();
		return $this->items;
	}

	public function getActivityList() {
		if ( $this->show_edits ) {
			$this->setEdits();
		}
		if ( $this->show_messages_sent ) {
			$this->getMessagesSent();
		}

		if ( $this->items ) {
			usort( $this->items, [ 'UserActivity', 'sortItems' ] );
		}
		return $this->items;
	}

	public function getActivityListGrouped() {
		$this->getActivityList();

		if ( $this->show_edits ) {
			$this->simplifyPageActivity( 'edit' );
		}
		if ( $this->show_messages_sent ) {
			$this->simplifyPageActivity( 'user_message' );
		}

		if ( !isset( $this->activityLines ) ) {
			$this->activityLines = [];
		}

		if ( isset( $this->activityLines ) && is_array( $this->activityLines ) ) {
			usort( $this->activityLines, [ 'UserActivity', 'sortItems' ] );
		}

		return $this->activityLines;
	}

	/**
	 * @param string $type Activity type, such as 'edit'
	 * @param bool $has_page True by default
	 */
	function simplifyPageActivity( $type, $has_page = true ) {
		global $wgLang;

		if ( !isset( $this->items_grouped[$type] ) || !is_array( $this->items_grouped[$type] ) ) {
			return;
		}

		foreach ( $this->items_grouped[$type] as $page_name => $page_data ) {
			$users = '';
			$pages = '';

			if ( $type == 'user_message' ) {
				$page_title = Title::newFromText( $page_name, NS_USER );
			} else {
				$page_title = Title::newFromText( $page_name );
			}

			$count_users = count( $page_data['users'] );
			$user_index = 0;
			$pages_count = 0;

			// Init empty variable to be used later on for GENDER processing
			// if the event is only for one user.
			$userNameForGender = '';

			foreach ( $page_data['users'] as $user_name => $action ) {
				if ( $page_data['timestamp'] < $this->three_days_ago ) {
					continue;
				}

				$count_actions = count( $action );

				if ( $has_page && !isset( $this->displayed[$type][$page_name] ) ) {
					$this->displayed[$type][$page_name] = 1;

					$pages .= ' <a href="' . htmlspecialchars( $page_title->getFullURL() ) . "\">" . htmlspecialchars( $page_name ) . "</a>";
					if ( $count_users == 1 && $count_actions > 1 ) {
						$pages .= wfMessage( 'word-separator' )->escaped();
						$pages .= wfMessage( 'parentheses' )->rawParams( wfMessage(
							// For grep: useractivity-group-edit, 
							// useractivity-group-user_message, 
							"useractivity-group-{$type}",
							$count_actions,
							$user_name
						)->escaped() )->escaped();
					}
					$pages_count++;
				}

				// Single user on this action,
				// see if we can stack any other singles
				if ( $count_users == 1 ) {
					$userNameForGender = $user_name;
					foreach ( $this->items_grouped[$type] as $page_name2 => $page_data2 ) {
						if ( !isset( $this->displayed[$type][$page_name2] ) &&
							count( $page_data2['users'] ) == 1
						) {
							foreach ( $page_data2['users'] as $user_name2 => $action2 ) {
								if ( $user_name2 == $user_name && $pages_count < 5 ) {
									$count_actions2 = count( $action2 );

									if (
										$type == 'user_message'
									) {
										$page_title2 = Title::newFromText( $page_name2, NS_USER );
									} else {
										$page_title2 = Title::newFromText( $page_name2 );
									}

									if ( $pages ) {
										$pages .= ', ';
									}
									if ( $page_title2 instanceof Title ) {
										$pages .= ' <a href="' . htmlspecialchars( $page_title2->getFullURL() ) . '">' . htmlspecialchars( $page_name2 ) . '</a>';
									}
									if ( $count_actions2 > 1 ) {
										$pages .= wfMessage( 'word-separator' )->escaped();
										$pages .= wfMessage( 'parentheses' )->rawParams( wfMessage(
											// For grep: useractivity-group-edit,
											// useractivity-group-user_message											"useractivity-group-{$type}",
											$count_actions2,
											$user_name
										)->escaped() )->escaped();
									}
									$pages_count++;

									$this->displayed[$type][$page_name2] = 1;
								}
							}
						}
					}
				}

				$user_index++;

				if ( $users && $count_users > 2 ) {
					$users .= wfMessage( 'comma-separator' )->escaped();
				}
				if ( $user_index == $count_users && $count_users > 1 ) {
					$users .= wfMessage( 'and' )->escaped();
				}

				$user_title = Title::makeTitle( NS_USER, $user_name );
				// @phan-suppress-next-line SecurityCheck-DoubleEscaped T290624
				$user_name_short = htmlspecialchars( $wgLang->truncateForVisual( $user_name, 15 ) );

				$safeTitle = htmlspecialchars( $user_title->getText() );
				$users .= ' <b><a href="' . htmlspecialchars( $user_title->getFullURL() ) . "\" title=\"{$safeTitle}\">{$user_name_short}</a></b>";
			}
			if ( $pages || $has_page == false ) {
				$this->activityLines[] = [
					'type' => $type,
					'timestamp' => $page_data['timestamp'],
					// For grep: useractivity-edit, useractivity-foe, useractivity-friend,
					// useractivity-gift, useractivity-user_message, useractivity-comment
					// @phan-suppress-next-line SecurityCheck-XSS Somewhat false alarm as per the comment below
					'data' => wfMessage( "useractivity-{$type}" )->rawParams(
						$users, $count_users, $pages, $pages_count,
						// $userNameForGender is not sanitized, but this parameter
						// is expected to be used for gender only
						$userNameForGender
					)->escaped()
				];
			}
		}
	}

	/**
	 * Get the correct icon for the given activity type.
	 *
	 * @param string $type Activity type, such as 'edit' or 'friend' (etc.)
	 * @return string Image file name (images are located inSocialProfile's
	 * images/ directory)
	 */
	static function getTypeIcon( $type ) {
		switch ( $type ) {
			case 'edit':
				return 'editIcon.gif';
			case 'comment':
				return 'comment.gif';
			case 'friend':
				return 'addedFriendIcon.png';
			case 'system_message':
				return 'challengeIcon.png';
			case 'user_message':
				return 'emailIcon.gif';
		}
	}

	/**
	 * "Fixes" a comment (such as a recent changes edit summary) by converting
	 * certain characters (such as the ampersand) into their encoded
	 * equivalents and, if necessary, truncates the comment
	 *
	 * @param string $comment Comment to "fix"
	 * @return string "Fixed" comment
	 */
	function fixItemComment( $comment ) {
		global $wgLang;
		if ( !$comment ) {
			return '';
		}
		$preview = $wgLang->truncateForVisual( $comment, 75 );
		// @phan-suppress-next-line SecurityCheck-DoubleEscaped T290624
		return htmlspecialchars( $preview );
	}

	/**
	 * Compares the timestamps of two given objects to decide how to sort them.
	 * Called by getActivityList() and getActivityListGrouped().
	 *
	 * @param array $x
	 * @param array $y
	 * @return int 0 if the timestamps are the same, -1 if $x's timestamp
	 * is greater than $y's, else 1
	 */
	private static function sortItems( $x, $y ) {
		if ( $x['timestamp'] == $y['timestamp'] ) {
			return 0;
		} elseif ( $x['timestamp'] > $y['timestamp'] ) {
			return -1;
		} else {
			return 1;
		}
	}
}
