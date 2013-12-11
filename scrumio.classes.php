<?php

class ScrumioItem {

  public $item_id;
  public $title;
  public $estimate;
  public $time_left;
  public $responsible;
  public $state;
  public $story_id;

  public function __construct($item) {
    global $api;
    // Set Item properties
    $this->item_id = $item['item_id'];
    $this->title = $item['title'];
    $this->link = $item['link'];

    foreach ($item['fields'] as $field) {
      if ($field['field_id'] == ITEM_STORY_ID) {
        $this->story_id = $field['values'][0]['value']['item_id'];
      }
      if ($field['field_id'] == ITEM_STATE_ID) {
        $this->state = $field['values'][0]['value'];
      }
      if ($field['field_id'] == ITEM_ESTIMATE_ID) {
        $this->estimate = 0;
        if ($field['values'][0]['value'] > 0) {
          $this->estimate = $field['values'][0]['value']/3600;
        }
      }
      if ($field['field_id'] == ITEM_TIMELEFT_ID) {
        $this->time_left = 0;
        if ($field['values'][0]['value'] > 0) {
          $this->time_left = $field['values'][0]['value']/3600;
        }
      }
      if ($field['field_id'] == ITEM_RESPONSIBLE_ID) {
        $this->responsible = array();
        if ($field['values'][0]['value'] > 0) {
          $this->responsible = $field['values'][0]['value'];
        }
      }
    }
  }

}

class ScrumioStory {

  public $item_id;
  public $title;
  public $product_owner;
  public $states;
  public $total_days;
  public $remaining_days;
  public $items;
  public $areas;

  public function __construct($item, $items, $estimate, $time_left, $states, $total_days, $remaining_days) {
    global $api;
    // Set Story properties
    $this->item_id = $item['item_id'];
    $this->title = $item['title'];
    $this->link = $item['link'];
    $this->areas = array();
    foreach ($item['fields'] as $field) {
      if ($field['field_id'] == STORY_OWNER) {
        $this->product_owner = $field['values'][0]['value'];
        break;
      }
      elseif (defined('STORY_AREA_ID') && $field['field_id'] == STORY_AREA_ID) {
        foreach ($field['values'] as $value) {
          $this->areas[] = $value['value'];
        }
      }
    }

    // Get all items for this story
    $this->items = $items;
    $this->estimate = $estimate;
    $this->time_left = $time_left;

    $this->states = $states;
    $this->total_days = $total_days;
    $this->remaining_days = $remaining_days;
  }

  public function get_areas_ids() {
    $list = array();
    foreach ($this->areas as $area) {
      $list[] = $area['id'];
    }
    return $list;
  }

  public function get_areas_class_list() {
    $list = array();
    foreach ($this->areas as $area) {
      $list[] = 'area-'.$area['id'];
    }
    return $list;
  }

  public function get_responsible() {
    $list = array();
    foreach ($this->items as $item) {
      if ($item->responsible) {
        $list[$item->responsible['user_id']] = $item->responsible;
      }
    }
    return $list;
  }

  public function get_items_by_state() {
    $list = array();
    foreach ($this->states as $state) {
      $list[$state] = array();
    }

    foreach ($this->items as $item) {
      $state = $item->state ? $item->state : STATE_NOT_STARTED;
      $list[$state][] = $item;
    }

    return $list;
  }

  public function get_status_text() {
    $states = $this->get_items_by_state();
    $total = count($this->items);
    $return = array();

    if (count($states[STATE_DEV_DONE]) > 0 && $total == (count($states[STATE_DEV_DONE])+count($states[STATE_QA_DONE])+count($states[STATE_PO_DONE]))) {
      $return = array('short' => 'testing', 'long' => 'ready for testing!');
    }
    elseif (count($states[STATE_QA_DONE]) > 0 && $total == (count($states[STATE_QA_DONE])+count($states[STATE_PO_DONE]))) {
      $return = array('short' => 'po', 'long' => 'ready for PO signoff!');
    }
    elseif (count($states['PO done']) > 0 && $total == count($states[STATE_PO_DONE])) {
      $return = array('short' => 'done', 'long' => 'all finished!');
    }

    return $return;
  }

  public function get_time_left() {
    return $this->time_left;
  }

  public function get_estimate() {
    return $this->estimate;
  }

  public function get_on_target_value() {
    $estimate = $this->get_estimate();
    $hours_per_day = $estimate/$this->total_days;
    $target_value = round($estimate-($this->remaining_days*$hours_per_day));
    return $target_value > $estimate ? $estimate : $target_value;
  }

  public function get_current_percent() {
    $target = $this->get_on_target_value();
    $total = $this->get_estimate();
    $current = $total-$this->get_time_left();
    $target_percent = $target/$total*100;
    return $current/$total*100;
  }

  public function get_current_target_percent() {
    $target = $this->get_on_target_value();
    $total = $this->get_estimate();
    $current = $total-$this->get_time_left();
    return $target/$total*100;
  }

}

class ScrumioSprint {

  public $item_id;
  public $title;
  public $start_date;
  public $end_date;
  public $states;
  public $total_days;
  public $remaining_days;
  public $stories;
  public $changes;

  public function __construct($sprint) {
    global $api;
    try {
      // Locate available states
      $items_app = $api->app->get(ITEM_APP_ID);
      $this->states = array();
      if(is_array($items_app['fields'])) {
        foreach ($items_app['fields'] as $field) {
          if ($field['field_id'] == ITEM_STATE_ID) {
            $this->states = $field['config']['settings']['allowed_values'];
            break;
          }
        }
      }
      // Find active sprint
      $sprint_id = $sprint['item_id'];

      // Set sprint properties
      $this->item_id = $sprint['item_id'];
      $this->title = $sprint['title'];
      foreach ($sprint['fields'] as $field) {
        if ($field['type'] == 'date') {
          $this->start_date = date_create($field['values'][0]['start'], timezone_open('UTC'));
          $this->end_date = date_create($field['values'][0]['end'], timezone_open('UTC'));
        }
      }

      $this->changes = new Burndown($this->start_date, $this->end_date);

      // Get all stories in this sprint
      $sort_by = defined('STORY_IMPORTANCE_ID') && STORY_IMPORTANCE_ID ? STORY_IMPORTANCE_ID : 'title';
      $sort_desc = defined('STORY_IMPORTANCE_ID') && STORY_IMPORTANCE_ID ? 1 : 0;
      $stories = $api->item->getItems(STORY_APP_ID, array(
        'limit' => 200,
        'sort_by' => $sort_by,
        'sort_desc' => $sort_desc,
        STORY_SPRINT_ID => $sprint_id,
      ));

      // Grab all story items for all stories in one go
      $stories_ids = array();
      $stories_items = array();
      $stories_estimates = array();
      $stories_time_left = array();
      foreach ($stories['items'] as $story) {
        $stories_ids[] = $story['item_id'];
        $stories_items[$story['item_id']] = array();
        $stories_estimates[$story['item_id']] = 0;
        $stories_time_left[$story['item_id']] = 0;
      }
      $raw = $api->item->getItems(ITEM_APP_ID, array(
        'limit' => 200,
        'sort_by' => 'title',
        ITEM_STORY_ID => join(';', $stories_ids),
      ));
      foreach ($raw['items'] as $item) {
        $local_item = new ScrumioItem($item);
        $stories_items[$local_item->story_id][] = $local_item;
        $stories_estimates[$local_item->story_id] = $stories_estimates[$local_item->story_id] + $local_item->estimate;
        $stories_time_left[$local_item->story_id] = $stories_time_left[$local_item->story_id] + $local_item->time_left;

        // get all the revisions for an item
        $revisions = $api->item->getRevisions($item["item_id"]);

        // we need to know the number of changes
        $number_changes = count($revisions);
        // the first one is always the creation - we don't need it
        for($i = 1; $i < $number_changes; $i++) {
          // get the changes and check if the TIME LEFT field has changed
          $revision = $api->item->getRevisionDiff($item["item_id"] , ($i - 1), $i);
          $revision = $revision[0];
          if($revision["field_id"] == ITEM_TIMELEFT_ID) {
            // get the date and associate the change
            // we need a burn down chart for a sprint.
            // we don't care about anything else at this point
            // here we get all the changes for each task
            $this->changes->add_time_change(new DateTime($revisions[$i]["created_on"], new DateTimeZone('Europe/London')), ($revision["from"][0]["value"] - $revision["to"][0]["value"]));
          }
        }
      }

      foreach ($stories['items'] as $story) {
        $items = $stories_items[$story['item_id']];
        $estimate = $stories_estimates[$story['item_id']] ? $stories_estimates[$story['item_id']] : '0';
        $time_left = $stories_time_left[$story['item_id']] ? $stories_time_left[$story['item_id']] : '0';

        if (count($items) > 0) {
          $this->stories[] = new ScrumioStory($story, $items, $estimate, $time_left, $this->states, $this->get_working_days(), $this->get_working_days_left());
        }
      }
      $this->changes->set_estimate($this->get_estimate());
    }
    catch (PodioError $e) {
      die("There was an error. The API responded with the error type <b>{$e->body['error']}</b> and the message <b>{$e->body['error_description']}</b>. The URL was <b>{$e->url}</b><br><a href='".url_for('logout')."'>Log out</a>");
    }
  }

  public function get_working_days() {
    return getWorkingDays(date_format($this->start_date, 'Y-m-d'), date_format($this->end_date, 'Y-m-d'));
  }

  public function get_working_days_left() {
    $start_date = date_create('now', timezone_open('UTC'));

    // We substract 1 here to be able to 'chase the target' rather than 'working ahead'
    return getWorkingDays(date_format($start_date, 'Y-m-d'), date_format($this->end_date, 'Y-m-d'))-1;
  }

  public function get_time_left() {
    static $list;
    if (!isset($list[$this->item_id])) {
      $list[$this->item_id] = 0;
      foreach ($this->stories as $story) {
        $list[$this->item_id] = $list[$this->item_id]+$story->get_time_left();
      }
    }
    return $list[$this->item_id] ? $list[$this->item_id] : '0';
  }

  public function get_estimate() {
    static $list;
    if (!isset($list[$this->item_id])) {
      $list[$this->item_id] = 0;
      foreach ($this->stories as $story) {
        $list[$this->item_id] = $list[$this->item_id]+$story->get_estimate();
      }
    }
    return $list[$this->item_id] ? $list[$this->item_id] : '0';
  }

  public function get_on_target_value() {
    static $list;
    if (!isset($list[$this->item_id])) {
      $estimate = $this->get_estimate();
      $total_days = $this->get_working_days();
      $remaining_days = $this->get_working_days_left();
      $hours_per_day = $estimate/$total_days;
      $target_value = round($estimate-($remaining_days*$hours_per_day));
      $list[$this->item_id] = $target_value > $estimate ? $estimate : $target_value;

    }
    return $list[$this->item_id];
  }

  public function get_planned_daily_burn() {
    static $list;
    if (!isset($list[$this->item_id])) {
      $estimate = $this->get_estimate();
      $total_days = $this->get_working_days();
      $hours_per_day = $estimate/$total_days;
      $list[$this->item_id] = round($hours_per_day, 2);
    }
    return $list[$this->item_id];
  }

  public function get_current_percent() {
    $target = $this->get_on_target_value();
    $total = $this->get_estimate();
    $current = $total-$this->get_time_left();
    $target_percent = $target/$total*100;
    return $current/$total*100;
  }

  public function get_current_target_percent() {
    $target = $this->get_on_target_value();
    $total = $this->get_estimate();
    $current = $total-$this->get_time_left();
    return $target/$total*100;
  }

  public function get_finished() {
    return $this->get_estimate()-$this->get_time_left();
  }

  public function get_on_target_delta() {
    return $this->get_finished()-$this->get_on_target_value();
  }

  public function get_changes() {
    return $this->changes;
  }

}

//The function returns the no. of business days between two dates and it skips the holidays
function getWorkingDays($startDate,$endDate,$holidays = array()){
  //The total number of days between the two dates. We compute the no. of seconds and divide it to 60*60*24
  //We add one to inlude both dates in the interval.
  $days = (strtotime($endDate) - strtotime($startDate)) / 86400 + 1;

  $no_full_weeks = floor($days / 7);
  $no_remaining_days = fmod($days, 7);

  //It will return 1 if it's Monday,.. ,7 for Sunday
  $the_first_day_of_week = gmdate("N",strtotime($startDate));
  $the_last_day_of_week = gmdate("N",strtotime($endDate));

  //---->The two can be equal in leap years when february has 29 days, the equal sign is added here
  //In the first case the whole interval is within a week, in the second case the interval falls in two weeks.
  if ($the_first_day_of_week <= $the_last_day_of_week){
    if ($the_first_day_of_week <= 6 && 6 <= $the_last_day_of_week) $no_remaining_days--;
    if ($the_first_day_of_week <= 7 && 7 <= $the_last_day_of_week) $no_remaining_days--;
  }
  else{
    if ($the_first_day_of_week <= 6) {
      //In the case when the interval falls in two weeks, there will be a weekend for sure
      $no_remaining_days = $no_remaining_days - 2;
    }
  }

  //The no. of business days is: (number of weeks between the two dates) * (5 working days) + the remainder
//---->february in none leap years gave a remainder of 0 but still calculated weekends between first and last day, this is one way to fix it
 $workingDays = $no_full_weeks * 5;
  if ($no_remaining_days > 0 )
  {
    $workingDays += $no_remaining_days;
  }

  //We subtract the holidays
  foreach($holidays as $holiday){
    $time_stamp=strtotime($holiday);
    //If the holiday doesn't fall in weekend
    if (strtotime($startDate) <= $time_stamp && $time_stamp <= strtotime($endDate) && gmdate("N",$time_stamp) != 6 && gmdate("N",$time_stamp) != 7)
      $workingDays--;
  }

  return $workingDays;
}

class Burndown {
  public $start_date;
  public $end_date;
  public $duration;
  public $today;
  public $estimate;
  public $time_changes = array(); // we index by date timestamp (at midnight)

  public function __construct($start, $end) {
    global $api;
    $this->start_date = $start;
    $this->end_date = $end;
    $this->duration = date_diff($this->end_date, $this->start_date, 1)->d + 1;
    $this->today = date_diff(new DateTime(), $this->start_date, 1)->d;
    // initialise the array values for all days
    for($i = 0; $i < $this->duration; $i++) {
      $this->time_changes[$i] = 0;
    }
  }

  public function set_estimate($estimate) {
    $this->estimate = $estimate;
  }

  public function add_time_change($date, $value) {
    // check if the date is within the range
    if($this->start_date > $date || $this->end_date < $date) {
      return false;
    }
    // work done on day 1 affects time left on day 2, and so on
    // we set 6am as our cut-off point

    // create a new DateTime object with the same date
    // but set the time to 6am
    $cutoff = clone $date;
    $cutoff->setTime(6, 0);
    if($cutoff > $date) {
      $date->sub(new DateInterval('P1D'));
    }
    $date->setTime(0, 0);
    $difference = date_diff($date, $this->start_date, 1);
    $this->time_changes[$difference->d] += ($value / 3600);
  }

  public function get_google_chart($labels) {
    // example of the format: https://google-developers.appspot.com/chart/interactive/docs/gallery/linechart
    $data = array();
    // $labels = array('Day', 'Expected', 'Current');
    $data[] = $labels;
    $data[] = array('Day 1', $this->estimate, $this->estimate);
    $done = 0;
    for($x = 1; $x <= $this->duration; $x++) {
      $done += $this->time_changes[$x-1];
      $estimated_progress = $this->estimate - round(($this->estimate/$this->duration) * $x, 1);
      if($x === $this->today) {
        $data[] = array('Today', $estimated_progress, $this->estimate - $done);
      } else if($x > $this->today) {
        $data[] = array('Day '. ($x + 1), $estimated_progress, null);
      } else {
        $data[] = array('Day '. ($x + 1), $estimated_progress, $this->estimate - $done);
      }
    }

    return $data;
  }

  public function dump() {
    return $this->time_changes;
  }
}