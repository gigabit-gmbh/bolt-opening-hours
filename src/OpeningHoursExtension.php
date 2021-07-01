<?php

namespace Bolt\Extension\Gigabit\OpeningHours;

use Bolt\Extension\SimpleExtension;

/**
 * OpeningHours extension class.
 *
 * @author Thomas Helmrich <thomas@gigabit.de>
 */
class OpeningHoursExtension extends SimpleExtension
{

    protected const DEFAULT_TEMPLATE = 'openingHours.twig';
    protected const DEFAULT_OVERVIEW_TEMPLATE = 'openingHoursOverview.twig';

    /**
     * {@inheritdoc}
     */
    protected function registerTwigFunctions()
    {
        return [
            'openingHours' => ['showOpeningHours', ['is_safe' => ['html']]],
            'openingHoursOverview' => ['showOpeningHoursOverview', ['is_safe' => ['html']]],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigFilters()
    {
        return [
            'simpleTime' => 'simpleTimeFilter',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigPaths()
    {
        return [
            'templates',
        ];
    }

    /**
     * @return string
     */
    public function showOpeningHours()
    {
        $todayDateTime = new \DateTime();
        $todayDate = new \DateTime("today midnight");

        $currentlyOpen = false;
        $opensToday = array("day" => null, "closeTime" => null);
        $opensNext = array("days" => null, "day" => null, "hours" => null, "nextWithinToday" => false);

        $currentDay = $todayDateTime->format("l");
        $config = $this->getConfig();
        $openingHoursSections = $config["opening-hours"];

        $openingHours = array();
        $openingHoursGrouped = array();

        foreach ($openingHoursSections as $sectionName => $section) {
            $validDates = $this->getValidFromToDates($section, $todayDateTime);
            if ($validDates["from"] < $todayDateTime && $validDates["to"] > $todayDateTime) {
                foreach ($section["times"] as $day => $hours) {
                    $openingDay = new \DateTime($day . " this week midnight");
                    $validHours = $this->compareNextOpeningHours(
                        $opensNext,
                        $todayDate->diff($openingDay),
                        $day,
                        $hours,
                        $todayDateTime,
                        $openingDay
                    );

                    $this->getGrouped($day, $hours, $config, $openingHoursGrouped);
                    $openingHours[$day] = $hours;

                    if ($day === $currentDay && $this->isHoliday($todayDate->format("Y-m-d")) === false) {
                        $openDate = $validHours['open'];
                        $closeDate = $validHours['close'];

                        if ($openDate <= $todayDateTime && $closeDate >= $todayDateTime) {
                            $opensToday["day"] = $day;
                            $opensToday["closeTime"] = $closeDate->format('H:i');
                            $currentlyOpen = true;
                        }
                    }
                }
            }
        }

        $template = $this::DEFAULT_TEMPLATE;
        if (array_key_exists('templates', $config) && array_key_exists('default', $config["templates"])) {
            $template = $config["templates"]["default"];
        }

        return $this->renderTemplate(
            $template,
            array(
                "isOpen" => $currentlyOpen,
                "opensToday" => $opensToday,
                "opensNext" => $opensNext,
                "openingHours" => $openingHours,
                "openingHoursGrouped" => $openingHoursGrouped,
                "displaySimpleTime" => $config["simpleTime"],
                "groupedDays" => $config["groupedDays"],
                "shortenGroupedDays" => $config["shortenGroupedDays"],
                "additionalMessage" => $config["additionalMessage"],
            )
        );
    }

    /**
     * @return string
     */
    public function showOpeningHoursOverview()
    {
        $todayDateTime = new \DateTime();

        $config = $this->getConfig();
        $openingHoursSections = $config["opening-hours"];

        $openingHours = array();
        $openingHoursGrouped = array();

        foreach ($openingHoursSections as $sectionName => $section) {
            $validDates = $this->getValidFromToDates($section, $todayDateTime);
            if ($validDates["from"] < $todayDateTime && $validDates["to"] > $todayDateTime) {
                foreach ($section["times"] as $day => $hours) {
                    $this->getGrouped($day, $hours, $config, $openingHoursGrouped);
                    $openingHours[$day] = $hours;
                }
            }
        }

        $template = $this::DEFAULT_OVERVIEW_TEMPLATE;
        if (array_key_exists('templates', $config) && array_key_exists('templates', $config["overview"])) {
            $template = $config["templates"]["overview"];
        }

        return $this->renderTemplate(
            $template,
            array(
                "openingHours" => $openingHours,
                "openingHoursGrouped" => $openingHoursGrouped,
                "displaySimpleTime" => $config["simpleTime"],
                "groupedDays" => $config["groupedDays"],
                "shortenGroupedDays" => $config["shortenGroupedDays"],
                "additionalMessage" => $config["additionalMessage"],
            )
        );
    }


    /**
     * If simple is set to true, it removes the :xx from the time display
     *
     * @param string $input
     *
     * @return string
     */
    public function simpleTimeFilter($input, $simple = true)
    {
        if ($simple === false) {
            return $input;
        }
        $time = new \DateTime();
        $time->modify("today " . $input);

        return $time->format("G:i");
    }

    /**
     * @param array $section
     * @param \DateTime $today
     *
     * @return array
     */
    protected function getValidFromToDates($section, $today)
    {
        $validFromMonth = explode("-", $section["valid-from"])[0];
        $validToMonth = explode("-", $section["valid-to"])[0];
        $validFromDay = explode("-", $section["valid-from"])[1];
        $validToDay = explode("-", $section["valid-to"])[1];
        $todayMonth = $today->format('m');
        $todayDay = $today->format('d');

        $toYear = clone $today;

        $fromYear = clone $today;

        if ($validFromMonth > $validToMonth) {
            // from should be next year
            $toYear->modify("+1 year");
        }

        $validFrom = new \DateTime($fromYear->format("Y") . "-" . $section["valid-from"]);
        $validTo = new \DateTime($toYear->format("Y") . "-" . $section["valid-to"]);

        return array("from" => $validFrom, "to" => $validTo);
    }

    /**
     * @param array $opensNext The array with the opensNext definitions
     * @param \DateInterval $dayDiff Day Diff array from today to given data day
     * @param string $day Name of the data day
     * @param array $openingHours The Opening Hours of the data day
     * @param \DateTime $today The current DateTime
     */
    protected function compareNextOpeningHours(&$opensNext, $dayDiff, $day, $openingHours, $today, $openingDay)
    {
        $setValues = false;
        $setToday = false;

        $validOpenDate = clone $openingDay;
        $validCloseDate = clone $openingDay;

        if ($dayDiff->days === 0) {
            $currentHour = intval($today->format('H'));

            if (array_key_exists("slots", $openingHours)) {
                $loop = 0;
                $validCloseHour = 0;
                foreach ($openingHours['slots'] as $slot) {
                    $timeSplitOpen = preg_split("/:/", $slot["open"]);
                    $timeSplitClose = preg_split("/:/", $slot["close"]);

                    if ($loop === 0) {
                        $validOpenDate->setTime($timeSplitOpen[0], $timeSplitOpen[1]);
                        $validCloseDate->setTime($timeSplitClose[0], $timeSplitClose[1]);
                    } else {
                        if (count($openingHours['slots']) > $loop) {
                            if ($currentHour >= $validCloseHour) {
                                $validOpenDate->setTime($timeSplitOpen[0], $timeSplitOpen[1]);
                                $validCloseDate->setTime($timeSplitClose[0], $timeSplitClose[1]);
                            }
                        }
                    }
                    $validCloseHour = intval($timeSplitClose[0]);
                    $loop++;
                }
            } else {
                $timeSplitOpen = preg_split("/:/", $openingHours["open"]);
                $timeSplitClose = preg_split("/:/", $openingHours["close"]);
                $validOpenDate->setTime($timeSplitOpen[0], $timeSplitOpen[1]);
                $validCloseDate->setTime($timeSplitClose[0], $timeSplitClose[1]);
            }

            // check if opening hour is before current time
            if ($today >= $validOpenDate && $today < $validCloseDate) {
                // currently opened
                return [
                    "open" => $validOpenDate,
                    "close" => $validCloseDate
                ];
            } else {
                // currently closed
                $setValues = true;
            }
        }

        $diffInDays = $dayDiff->days;
        if ($dayDiff->invert) {
            $diffInDays = 7 - $diffInDays;
        }
        if ($opensNext["days"] === null) {
            $setValues = true;
        }
        if ($opensNext["days"] > $diffInDays && $setValues === false) {
            $setValues = true;
        }

        if ($validCloseDate < $today && $openingDay->format("d.m.Y") === $today->format("d.m.Y")) {
            $setValues = false;
        } else {
            if ($validOpenDate->format("d.m.Y") === $today->format("d.m.Y")) {
                $setToday = true;
            }
        }

        if ($setValues) {
            $opensNext["days"] = $diffInDays;
            $opensNext["day"] = $day;
            if (array_key_exists("slots", $openingHours)) {
                $opensNext["hours"] = null;
                foreach ($openingHours["slots"] as $slot) {
                    $testDateOpen = clone $today;
                    $testDateClose = clone $today;
                    $timeSplitOpen = preg_split("/:/", $slot["open"]);
                    $timeSplitClose = preg_split("/:/", $slot["close"]);

                    $testDateOpen->setTime($timeSplitOpen[0], $timeSplitOpen[1]);
                    $testDateClose->setTime($timeSplitClose[0], $timeSplitClose[1]);
                    $testDateOpen->modify('+' . $diffInDays . " days");
                    $testDateClose->modify('+' . $diffInDays . " days");

                    if ($testDateOpen >= $today && $today < $testDateClose && $opensNext["hours"] === null) {
                        $opensNext["hours"] = $slot;
                    }
                }
            } else {
                $opensNext["hours"] = $openingHours;
            }
            $opensNext["nextWithinToday"] = $setToday;
        };

        return [
            "open" => $validOpenDate,
            "close" => $validCloseDate
        ];
    }

    /**
     * @param string $time im Format Y-M-D ( YYYY-MM-DD )
     *
     * @return bool|string
     */
    protected function isHoliday($time)
    {
        $datum = explode("-", $time);

        $datum[1] = str_pad($datum[1], 2, "0", STR_PAD_LEFT);
        $datum[2] = str_pad($datum[2], 2, "0", STR_PAD_LEFT);

        if (!checkdate($datum[1], $datum[2], (int)$datum[0])) {
            return false;
        }

        $easter_d = date("d", easter_date($datum[0]));
        $easter_m = date("m", easter_date($datum[0]));

        switch ($datum[1] . $datum[2]) {
            case '0101':
                return 'Neujahr';
            case '0106':
                return 'Heilige Drei Könige';
            case $easter_m . $easter_d:
                return 'Ostersonntag';
            case $this->getEasterDayMonth($datum[0], 1):
                return 'Ostermontag';
            case $this->getEasterDayMonth($datum[0], 39):
                return 'Christi Himmelfahrt';
            case $this->getEasterDayMonth($datum[0], 49):
                return 'Pfingstsonntag';
            case $this->getEasterDayMonth($datum[0], 50):
                return 'Pfingstmontag';
            case $this->getEasterDayMonth($datum[0], 60):
                return 'Fronleichnam';
            case '0501':
                return 'Erster Mai';
            case '0815':
                return 'Mariä Himmelfahrt';
            case '1101':
                return 'Allerheiligen';
            case '1224':
                return 'Heiliger Abend';
            case '1225':
                return 'Christtag';
            case '1226':
                return 'Stefanitag';
            default:
                return false;
        }
    }

    protected function getEasterDayMonth($year, $offset)
    {
        $easterDay = date("d", easter_date($year));
        $easterMonth = date("m", easter_date($year));

        return date("md", mktime(0, 0, 0, $easterMonth, $easterDay + $offset, $year));
    }

    /**
     * Render a Twig template.
     *
     * @param string $template
     * @param array $context
     *
     * @return string
     *
     * @throws
     */
    protected function renderTemplate($template, array $context = [])
    {
        if ($template === self::DEFAULT_TEMPLATE || $template === self::DEFAULT_OVERVIEW_TEMPLATE) {
            return parent::renderTemplate($template, $context);
        }

        $app = $this->getContainer();

        return $app['twig']->render($template, $context);
    }

    /**
     * @param $day
     * @param $hours
     * @param $config
     * @param $openingHoursGrouped
     */
    protected function getGrouped($day, $hours, $config, &$openingHoursGrouped)
    {
        if (false === ($config["groupedDays"] && isset($hours["group"]))) {
            return false;
        }
        if (array_key_exists($hours["group"], $openingHoursGrouped) === false) {
            $openingHoursGrouped[$hours["group"]] = array();
        }

        if (array_key_exists($day, $openingHoursGrouped[$hours["group"]]) === false) {
            $openingHoursGrouped[$hours["group"]][$day] = array();
        }

        if (array_key_exists("slots", $hours)) {
            foreach ($hours['slots'] as $slot) {
                $openingHoursGrouped[$hours["group"]][$day][] = [
                    'open' => $slot['open'],
                    'close' => $slot['close'],
                ];
            }
        } else {
            $openingHoursGrouped[$hours["group"]][$day][] = [
                'open' => $hours['open'],
                'close' => $hours['close'],
            ];
        }
    }

}