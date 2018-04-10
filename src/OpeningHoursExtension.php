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

    /**
     * {@inheritdoc}
     */
    protected function registerTwigFunctions()
    {
        return [
            'openingTimes' => ['showOpeningTimes', ['is_safe' => ['html']]],
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
    public function showOpeningTimes()
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
            $validFromMonth = explode("-", $section["valid-from"])[0];
            $validToMonth = explode("-", $section["valid-to"])[0];
            $toYear = $todayDateTime->format("Y");
            if ($validToMonth < $validFromMonth) {
                $toYear = (intval($todayDateTime->format("Y")) + 1);
            }
            $validFrom = new \DateTime($todayDateTime->format("Y")."-".$section["valid-from"]);
            $validTo = new \DateTime($toYear."-".$section["valid-to"]);

            if ($validFrom < $todayDateTime && $validTo > $todayDateTime) {
                foreach ($section["times"] as $day => $hours) {
                    $openingDay = new \DateTime($day." this week midnight");
                    $this->compareNextOpeningHours($opensNext, $todayDate->diff($openingDay), $day, $hours);

                    if ($config["groupedDays"] && isset($hours["group"])) {
                        if (array_key_exists($hours["group"], $openingHoursGrouped) === false) {
                            $openingHoursGrouped[$hours["group"]] = array();
                        }
                        $openingHoursGrouped[$hours["group"]][] = array(
                            "day" => $day,
                            "hours" => $hours,
                        );
                    }
                    $openingHours[$day] = $hours;

                    if ($day === $currentDay && $this->isHoliday($todayDate->format("Y-m-d")) === false) {
                        $openDate = new \DateTime($todayDateTime->format("Y-m-d ").$hours["open"].":00");
                        $closeDate = new \DateTime($todayDateTime->format("Y-m-d ").$hours["close"].":00");
                        if ($openDate < $todayDateTime && $closeDate > $todayDateTime) {
                            $opensToday["day"] = $day;
                            $opensToday["hours"] = $hours;
                            $currentlyOpen = true;
                        }
                    }

                }
            }
        }

        return $this->renderTemplate(
            'openingTimes.twig',
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
        $time->modify("today ".$input);

        return $time->format("G");
    }

    /**
     * @param array $opensNext The array with the opensNext definitions
     * @param \DateInterval $dayDiff Day Diff array from today to given data day
     * @param string $day Name of the data day
     * @param array $openingHours The Opening Hours of the data day
     */
    protected function compareNextOpeningHours(&$opensNext, $dayDiff, $day, $openingHours)
    {
        if ($dayDiff->days === 0) {
            return;
        }
        $diffInDays = $dayDiff->days;
        if ($dayDiff->invert) {
            $diffInDays = 7 - $diffInDays;
        }

        $setValues = false;
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

        $status = false;

        if ($datum[1].$datum[2] == '0101') {
            return 'Neujahr';
        } elseif ($datum[1].$datum[2] == '0106') {
            return 'Heilige Drei Könige';
        } elseif ($datum[1].$datum[2] == $easter_m.$easter_d) {
            return 'Ostersonntag';
        } elseif ($datum[1].$datum[2] == date("md", mktime(0, 0, 0, $easter_m, $easter_d + 1, $datum[0]))) {
            return 'Ostermontag';
        } elseif ($datum[1].$datum[2] == date("md", mktime(0, 0, 0, $easter_m, $easter_d + 39, $datum[0]))) {
            return 'Christi Himmelfahrt';
        } elseif ($datum[1].$datum[2] == date("md", mktime(0, 0, 0, $easter_m, $easter_d + 49, $datum[0]))) {
            return 'Pfingstsonntag';
        } elseif ($datum[1].$datum[2] == date("md", mktime(0, 0, 0, $easter_m, $easter_d + 50, $datum[0]))) {
            return 'Pfingstmontag';
        } elseif ($datum[1].$datum[2] == date("md", mktime(0, 0, 0, $easter_m, $easter_d + 60, $datum[0]))) {
            return 'Fronleichnam';
        } elseif ($datum[1].$datum[2] == '0501') {
            return 'Erster Mai';
        } elseif ($datum[1].$datum[2] == '0815') {
            return 'Mariä Himmelfahrt';
        } elseif ($datum[1].$datum[2] == '1101') {
            return 'Allerheiligen';
        } elseif ($datum[1].$datum[2] == '1224') {
            return 'Heiliger Abend';
        } elseif ($datum[1].$datum[2] == '1225') {
            return 'Christtag';
        } elseif ($datum[1].$datum[2] == '1226') {
            return 'Stefanitag';
        } else {
            return $status;
        }
    }

}
