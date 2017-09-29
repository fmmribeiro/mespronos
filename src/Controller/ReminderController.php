<?php

/**
 * @file
 * Contains Drupal\mespronos\Controller\ReminderController.
 */

namespace Drupal\mespronos\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\mespronos\Entity\Day;
use Drupal\Core\Database\Database;
use Drupal\mespronos\Entity\Reminder;
use Drupal\user\Entity\User;
use Drupal\Core\Url;

/**
 * Class ReminderController.
 *
 * @package Drupal\mespronos\Controller
 */
class ReminderController extends ControllerBase {

  public static function init() {
    if (!self::isEnabled()) {
      return false;
    }
    $hours = self::getHoursDefined();
    $upcommings_games = self::getUpcomming($hours);

    $users = self::getUserWithEnabledReminder();

    $user_to_remind = self::getUsersToRemind($users, $upcommings_games);
    if (count($user_to_remind) > 0) {
      $days = self::getDaysFromGames($upcommings_games);
      foreach ($days as $day) {
        /** @var Day $day */
        $betters_on_league = self::getBetterOnLeague($day->getLeagueID());
        $user_to_remind_on_this_day = self::getUsersToRemindOnThisDay($user_to_remind, $betters_on_league);
        $nb_mail = self::sendReminder($user_to_remind_on_this_day, $day);
        if ($nb_mail > 0) {
          $reminder = Reminder::create(array(
            'day' => $day->id(),
            'emails_sended' => $nb_mail,
          ));
          $reminder->save();
          \Drupal::logger('mespronos_reminder')->info(t('Reminder sended for day #@id (@game_label) : @nb_mail mails sended (total of @nb_user member with reminder enabled)', [
            '@id'=>$day->id(),
            '@game_label'=>$day->label(),
            '@nb_mail' => $nb_mail,
            '@nb_user' => count($users),
          ]));
        }
      }
    }
    return true;
  }

  public static function isEnabled() {
    $config = \Drupal::config('mespronos.reminder');
    return $config->get('enabled') == true ? true : false;
  }

  public static function getHoursDefined() {
    $config = \Drupal::config('mespronos.reminder');
    $hours = $config->get('hours');
    return !is_null($hours) ? $hours : [];
  }

  public static function sendReminder($users_to_remind, $day) {
    $league = $day->getLeague();
    if (count($users_to_remind) == 0) {
      return false;
    }
    $nb_mail = 0;
    foreach ($users_to_remind as $user_to_remind) {
      $nb_mail++;
      $user = User::load($user_to_remind);
      $mailManager = \Drupal::service('plugin.manager.mail');
      $params = [];

      $mail = self::getReminderEmailVariables($user, $day);

      $params['message'] = self::getReminderEmailRendered($mail);
      $params['subject'] = t('@sitename - Bet Reminder - @league - @day', [
        '@sitename'=>\Drupal::config('system.site')->get('name'),
        '@league'=>$league->label(),
        '@day'=>$day->label(),
      ]);

      $mailManager->mail('mespronos', 'reminder', $user->getEmail(), $user->getPreferredLangcode(), $params, null, TRUE);
    }
    return $nb_mail;
  }

  public static function getReminderEmailVariables(User $user, Day $day) {
    $league = $day->getLeague();
    $games = $day->getGames();
    $emailvars = [];
    $emailvars['#theme'] = 'bet-reminder';
    $destination_my_account = Url::fromRoute('entity.user.edit_form', ['user' => $user->id()]);

    $emailvars['#user'] = [
      'name' => $user->getAccountName(),
      'myaccount' => Url::fromRoute('user.login', [], ['absolute'=>true, 'query'=>['destination' => $destination_my_account->toString()]]),
    ];
    $destination = Url::fromRoute('mespronos.day.bet', ['day'=>$day->id()]);

    $emailvars['#day'] = [
      'label' => $league->label().' - '.$day->label(),
      'games' => [],
      'bet_link' => Url::fromRoute('user.login', [], ['absolute'=>true, 'query'=>['destination'=>$destination->toString()]]),
    ];

    foreach ($games as $game) {
      $date = new \DateTime($game->getGameDate(), new \DateTimeZone('UTC'));
      $date->setTimezone(new \DateTimeZone("Europe/Paris"));
      $emailvars['#day']['games'][] = [
        'team1' => $game->getTeam1()->label(),
        'team1_logo' => $game->getTeam1()->getLogo('mini_thumbnail'),
        'team2' => $game->getTeam2()->label(),
        'team2_logo' => $game->getTeam1()->getLogo('mini_thumbnail'),
        'date' => \Drupal::service('date.formatter')->format($date->format('U'), 'date_longue_sans_annee'),
      ];
    };

    return $emailvars;
  }

  public static function getReminderEmailRendered($variables) {
    $rederer = \Drupal::service('renderer');
    $rendered_email = $rederer->renderPlain($variables);
    return $rendered_email;
  }

  /**
   * Return all days that plays between now and $nb_hours;
   * @param int $nb_hours number of hours
   * @return \Drupal\mespronos\Entity\Game[]
   */
  public static function getUpcomming($nb_hours) {
    $date_to = new \DateTime(null, new \DateTimeZone("UTC"));
    $date_to->add(new \DateInterval('PT'.intval($nb_hours).'H'));
    $now = new \DateTime(null, new \DateTimeZone("UTC"));

    $game_storage = \Drupal::entityTypeManager()->getStorage('game');
    $query = \Drupal::entityQuery('game');

    $query->condition('game_date', $now->format('Y-m-d\TH:i:s'), '>');
    $query->condition('game_date', $date_to->format('Y-m-d\TH:i:s'), '<=');

    $group = $query->orConditionGroup()
      ->condition('score_team_1', null, 'is')
      ->condition('score_team_2', null, 'is');

    $query->sort('game_date', 'ASC');
    $query->sort('id', 'ASC');

    $ids = $query->condition($group)->execute();

    $games = $game_storage->loadMultiple($ids);

    self::checkIfReminderAlreadySended($games);
    if (count($games) > 0) {
      \Drupal::logger('mespronos_reminder')->debug(t('@nb_games games upcomming in the next @hour hours', [
        '@nb_games'=>count($games),
        '@hour'=>$nb_hours,
      ]));
    }

    return $games;

    }

  public static function checkIfReminderAlreadySended(&$games) {
    $days = [];
    foreach ($games as $key => $game) {
      $day_id = $game->getDayId();
      if(isset($days[$day_id]) && $days[$day_id]) {
        unset($games[$key]);
      } elseif(Reminder::loadForDay($day_id)) {
        $days[$day_id] = true;
        unset($games[$key]);
      }
    }
  }

  public static function getUserWithEnabledReminder() {
    $query = \Drupal::entityQuery('user')
      ->condition('status', 1)
      ->condition('field_reminder_enable.value', 1);
    $uids = $query->execute();
    return $uids;
  }

  public static function getUsersToRemind($users, $upcommings_games) {
    $user_to_remind = [];
    foreach ($users as $user_id) {
      if (self::doUserHasMissingBets($user_id, $upcommings_games)) {
        $user_to_remind[] = $user_id;
      }
    }
    return $user_to_remind;
  }

  public static function getUsersToRemindOnThisDay($user_to_remind, $betters_on_league) {
    $user_to_remind_on_this_day = [];
    foreach ($user_to_remind as $user) {
      if (in_array($user, $betters_on_league)) {
        $user_to_remind_on_this_day[] = $user;
      }
    }
    return $user_to_remind_on_this_day;
  }

  /**
   * @param integer $user_id id of user
   * @return \Drupal\mespronos\Entity\Game[]
   */
  public static function doUserHasMissingBets($user_id, $games) {
    if (count($games) == 0) {return false; }
    $games_id = array_map(function($a) {return $a->id(); },$games);
    $injected_database = Database::getConnection();

    $query = $injected_database->select('mespronos__bet', 'b');
    $query->addExpression('count(b.id)', 'nb_bets_done');
    $query->condition('b.game', $games_id, 'IN');
    $query->condition('b.better', $user_id);
    $results = $query->execute()->fetchAssoc();

    return $results['nb_bets_done'] < count($games_id);
  }

  public static function getDaysFromGames($games) {
    $days = [];
    foreach ($games as $game) {
      $day_id = $game->getDayId();
      if (!isset($days[$day_id])) {
        $days[$day_id] = $game->getDay();
      }
    }
    return $days;
  }

  public static function getBetterOnLeague($league_id) {
    $injected_database = Database::getConnection();
    $query = $injected_database->select('mespronos__ranking_league', 'rl');
    $query->fields('rl', ['better']);
    $query->condition('rl.league', $league_id);
    $results = $query->execute()->fetchAllKeyed(0, 0);
    return array_values($results);
  }



}
