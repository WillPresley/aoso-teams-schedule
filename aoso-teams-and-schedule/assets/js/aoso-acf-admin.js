/**
 * AOSO – ACF admin seeder for Matchdays (robust fix)
 *
 * Replaces the previous seeder. Fixes ordering/race issues by:
 * - Always re-querying repeater rows after adding rows
 * - Addressing the first 3 rows explicitly with .eq(i)
 * - Ensuring each Field row receives BOTH time rows
 * - Using ACF API where possible, falling back to clicking add buttons
 *
 * Place at:
 *  wp-content/plugins/aoso-teams-and-schedule/assets/js/aoso-acf-admin.js
 *
 * Keep this unminified while testing and make sure you enqueue it with deps:
 *  ['acf-input', 'jquery']
 */
(function ($) {
    "use strict";

    if (typeof acf === "undefined") {
        console.warn("AOSO: acf not found; aborting seeder");
        return;
    }
    console.log("AOSO: admin seeder loaded");

    // ---------- CONFIG ----------
    const DEFAULT_FIELDS = [
        { name: "Field 1", bg: "#f4cccc" },
        { name: "Field 2", bg: "#d9d2e9" },
        { name: "Field 3", bg: "#cfe1f3" },
    ];
    const DEFAULT_TIMES = ["9:00", "10:30"];

    // Keys from your PHP ACF declaration
    const KEYS = {
        matchdays: "field_aoso_matchdays",
        fields: "field_aoso_md_fields",
        field_name: "field_aoso_md_field_name",
        field_bg: "field_aoso_md_field_bg",
        times: "field_aoso_md_times",
        time_label: "field_aoso_md_time_label",
    };

    // ---------- Helpers ----------
    // Find repeater element by data-key (preferred) or data-name fallback
    function findRepeaterEl(key) {
        let $el = $(`[data-key="${key}"]`);
        if ($el.length) return $el.first();
        $el = $(`[data-name="${key.replace(/^field_/, "")}"]`); // defensive fallback
        return $el.first();
    }

    // Get repeater rows in either 'row' or 'table' layout
    function getRepeaterRows($repeaterEl) {
        let $rows = $repeaterEl.find("> .acf-input .acf-repeater > .acf-rows > .acf-row");
        if ($rows.length) return $rows;
        $rows = $repeaterEl.find("> .acf-input .acf-repeater > .acf-table > tbody > tr.acf-row");
        if ($rows.length) return $rows;
        // ultimate fallback
        return $repeaterEl.find(".acf-row");
    }

    // Add a row to a repeater. Prefer acf.getField().add(); fallback to clicking add button.
    function addRepeaterRow($repeaterEl) {
        try {
            const fieldObj = acf.getField($repeaterEl);
            if (fieldObj && typeof fieldObj.add === "function") {
                const rowObj = fieldObj.add();
                if (rowObj && rowObj.$el) return rowObj.$el;
            }
        } catch (err) {
            // fall through to button fallback
        }

        const $btn = $repeaterEl.find('> .acf-actions .acf-button[data-event="add-row"]').first();
        if ($btn.length) {
            $btn.trigger("click");
            // return last row (ACF immediately updates DOM in most cases)
            return getRepeaterRows($repeaterEl).last();
        }

        console.warn("AOSO: unable to add repeater row (no API & no add button)");
        return null;
    }

    // Set a field input value inside a container (data-key preferred, then data-name)
    function setFieldValue($container, key, value) {
        const $wrapKey = $container.find(`[data-key="${key}"]`).first();
        if ($wrapKey.length) {
            const $input = $wrapKey.find("input, textarea, select").first();
            if ($input.length) {
                $input.val(value).trigger("input").trigger("change");
                return true;
            }
        }
        // fallback by data-name
        const name = key.replace(/^field_/, "");
        const $wrapName = $container.find(`[data-name="${name}"]`).first();
        if ($wrapName.length) {
            const $input = $wrapName.find("input, textarea, select").first();
            if ($input.length) {
                $input.val(value).trigger("input").trigger("change");
                return true;
            }
        }
        return false;
    }

    // ---------- Main seeding routine for a single Matchday row ----------
    function seedMatchdayRow($matchdayRow) {
        console.log("AOSO: seedMatchdayRow starting for row", $matchdayRow);

        // find the Fields repeater inside this matchday
        const $fieldsRep = $matchdayRow.find(`[data-key="${KEYS.fields}"], [data-name="fields"]`).first();
        if (!$fieldsRep.length) {
            console.warn("AOSO: fields repeater not found inside matchday row");
            return;
        }

        // Ensure there are exactly 3 field rows (or at least 3)
        let tries = 0;
        while (getRepeaterRows($fieldsRep).length < 3 && tries < 6) {
            addRepeaterRow($fieldsRep);
            tries++;
        }

        // Re-query rows and operate on explicit indexes (.eq)
        let $fieldRows = getRepeaterRows($fieldsRep);
        console.log("AOSO: field rows count after adding:", $fieldRows.length);

        for (let i = 0; i < 3; i++) {
            const $fieldRow = $fieldRows.eq(i);
            if (!$fieldRow.length) {
                console.warn("AOSO: expected field row", i, "not found");
                continue;
            }

            const def = DEFAULT_FIELDS[i] || { name: "Field " + (i + 1), bg: "" };
            const okName =
                setFieldValue($fieldRow, KEYS.field_name, def.name) || setFieldValue($fieldRow, "field_name", def.name);
            const okBg =
                setFieldValue($fieldRow, KEYS.field_bg, def.bg) || setFieldValue($fieldRow, "field_bg", def.bg);

            if (!okName) console.warn("AOSO: failed to set field_name for row", i);
            if (!okBg) console.warn("AOSO: failed to set field_bg for row", i);

            // Times repeater inside this field row
            const $timesRep = $fieldRow.find(`[data-key="${KEYS.times}"], [data-name="times"]`).first();
            if (!$timesRep.length) {
                console.warn("AOSO: times repeater not found for field row", i);
                continue;
            }

            // Ensure exactly 2 time rows (or at least 2)
            let ttries = 0;
            while (getRepeaterRows($timesRep).length < 2 && ttries < 6) {
                addRepeaterRow($timesRep);
                ttries++;
            }

            // Re-query times and set labels for first two rows
            let $timeRows = getRepeaterRows($timesRep);
            console.log("AOSO: time rows count for field", i, $timeRows.length);

            for (let j = 0; j < 2; j++) {
                const $trow = $timeRows.eq(j);
                if (!$trow.length) {
                    console.warn("AOSO: expected time row", j, "not found for field", i);
                    continue;
                }
                const okTime =
                    setFieldValue($trow, KEYS.time_label, DEFAULT_TIMES[j]) ||
                    setFieldValue($trow, "time_label", DEFAULT_TIMES[j]);
                if (!okTime) console.warn("AOSO: failed to set time_label for time", j, "field", i);
            }
        }

        console.log("AOSO: seedMatchdayRow finished");
    }

    // ---------- Hooks ----------
    // When the matchdays field is ready on page load, if there are no rows, create/seed one.
    acf.addAction(`ready_field/key=${KEYS.matchdays}`, function (field) {
        console.log("AOSO: ready_field for matchdays");
        const $rep = field.$el;
        if (getRepeaterRows($rep).length === 0) {
            const $new = addRepeaterRow($rep);
            if ($new) {
                setTimeout(function () {
                    seedMatchdayRow($new);
                }, 40);
            }
        }
    });

    // When a row is appended to matchdays (user clicks Add Matchday)
    acf.addAction(`append_field/key=${KEYS.matchdays}`, function (field) {
        console.log("AOSO: append_field for matchdays");
        setTimeout(function () {
            const $rep = field.$el;
            const $rows = getRepeaterRows($rep);
            const $last = $rows.last();
            if ($last.length) seedMatchdayRow($last);
        }, 60);
    });

    // Generic append fallback
    acf.addAction("append", function ($el) {
        const $row = $el.is(".acf-row") ? $el : $el.find(".acf-row").first();
        if (!$row.length) return;
        if ($row.closest(`[data-key="${KEYS.matchdays}"], [data-name="aoso_matchdays"]`).length) {
            setTimeout(function () {
                seedMatchdayRow($row);
            }, 60);
        }
    });

    // Intercept add-row click as last fallback
    $(document).on(
        "click",
        `[data-key="${KEYS.matchdays}"] .acf-actions [data-event="add-row"], [data-name="aoso_matchdays"] .acf-actions [data-event="add-row"]`,
        function () {
            const $rep = $(this).closest(`[data-key="${KEYS.matchdays}"], [data-name="aoso_matchdays"]`);
            setTimeout(function () {
                const $rows = getRepeaterRows($rep);
                const $last = $rows.last();
                if ($last.length) seedMatchdayRow($last);
            }, 80);
        }
    );

    // Manual debug hook
    window.AOSO_seed_debug = function () {
        const $rep = findRepeaterEl(KEYS.matchdays);
        if (!$rep.length) return console.warn("AOSO: matchdays repeater not found");
        const $last = getRepeaterRows($rep).last();
        if (!$last.length) return console.warn("AOSO: no matchday rows to seed");
        seedMatchdayRow($last);
    };
})(jQuery);
