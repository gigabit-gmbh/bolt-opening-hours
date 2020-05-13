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
        $opensToday = array("day" => null, "hours" => null);
        $opensNext = array("days" => null, "day" => null, "hours" => null);

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
                    $this->compareNextOpeningHours(
                        $opensNext,
                        $todayDate->diff($openingDay),
                        $day,
                        $hours,
                        $todayDateTime
                    );

                    $this->getGrouped($day, $hours, $config, $openingHoursGrouped);
                    $openingHours[$day] = $hours;

                    if ($day === $currentDay && $this->isHoliday($todayDate->format("Y-m-d")) === false) {
                        $openDate = new \DateTime($todayDateTime->format("Y-m-d ") . $hours["open"] . ":00");
                        $closeDate = new \DateTime($todayDateTime->format("Y-m-d ") . $hours["close"] . ":00");
                        if ($openDate <= $todayDateTime && $closeDate >= $todayDateTime) {
                            $opensToday["day"] = $day;
                            $opensToday["hours"] = $hours;
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

        return $time->format("G");
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
        $todayMonth = $today->format('m');

        $toYear = clone $today;

        $fromYear = clone $today;

        if ($validFromMonth > $todayMonth && $validToMonth > $todayMonth && $validFromMonth > $validToMonth) {
            // e.g. current: 01, from: 10, to: 04
            $fromYear->modify("-1 year");
        }
        if ($validFromMonth > $todayMonth && $validToMonth <= $todayMonth && $validFromMonth > $validToMonth) {
            // e.g. current: 04, from: 10, to: 04
            $toYear->modify("+1 year");
        }
        if ($validFromMonth <= $todayMonth && $validToMonth < $todayMonth && $validFromMonth > $validToMonth) {
            // e.g. current: 10, from: 10, to: 04
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
    protected function compareNextOpeningHours(&$opensNext, $dayDiff, $day, $openingHours, $today)
    {
        $setValues = false;

        if ($dayDiff->days === 0) {
            $compareDate = $today->format("Y-m-d ") . $openingHours["open"] . ":00";
            $openDate = new \DateTime($compareDate);

            // check if opening hour is before current time
            if ($openDate > $today) {
                $setValues = true;
            } else {
                return;
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
        if ($setValues) {
            $opensNext["days"] = $diffInDays;
            $opensNext["day"] = $day;
            $opensNext["hours"] = $openingHours;
        }
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
        if ($config["groupedDays"] && isset($hours["group"])) {
            if (array_key_exists($hours["group"], $openingHoursGrouped) === false) {
                $openingHoursGrouped[$hours["group"]] = array();
            }
            $openingHoursGrouped[$hours["group"]][] = array(
                "day" => $day,
                "hours" => $hours,
            );
        }
    }

}
