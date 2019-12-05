/**
 * Custom site-wide Javascript goes here
 */

/**
 * first thing to do is set the global timezone for this session for use in moment.js
 * NOTE:
 * the site object is dynamically defined in config.js
 * (which is the best place to put global vars required across all pages)
 */
(function () {
    moment.tz.setDefault(site.time_zone);
    moment.updateLocale(site.locale.substr(0, 2), {
        week : {
            dow : 1,
        }
    });
})();

/**
 * array of objects for each AP model we know of
 * NOTE:
 * needs to be updated when new models are released or capabilities change
 * TODO:
 * add more capabilities here?
 * DFS capable/approved e.g.
 */
var ap_models_array = [
    {
        model:     'BZ2',
        full_name: 'UAP',
        dual_band: false
    },
    {
        model:     'BZ2LR',
        full_name: 'UAP LR',
        dual_band: false
    },
    {
        model:     'U2O',
        full_name: 'UAP Outdoor',
        dual_band: false
    },
    {
        model:     'U7LT',
        full_name: 'AC-LITE',
        dual_band: true
    },
    {
        model:     'U7LR',
        full_name: 'AC-LR',
        dual_band: true
    },
    {
        model:     'U7P',
        full_name: 'AC-PRO',
        dual_band: true
    },
    {
        model:     'U7PG2',
        full_name: 'AC-PRO',
        dual_band: true
    },
    {
        model:     'U2HSR',
        full_name: 'UAP Outdoor+',
        dual_band: false
    },
    {
        model:     'p2N',
        full_name: 'PicoStation M2',
        dual_band: false
    },
    {
        model:     'U2IW',
        full_name: 'AP IN-WALL',
        dual_band: false
    },
    {
        model:     'U7MSH',
        full_name: 'AC-MESH',
        dual_band: true
    },
    {
        model:     'U7MP',
        full_name: 'AC-MESH-PRO',
        dual_band: true
    },
    {
        model:     'U7HD',
        full_name: 'AC-HD',
        dual_band: true
    },
    {
        model:     'U7SHD',
        full_name: 'AC-SHD',
        dual_band: true
    }
];

/**
 * return the full name for a given AP model code
 */
function getApModelName(model) {
    var ap_found = _.find(ap_models_array, ['model', model]);
    if (ap_found == null) {
        return model;
    } else {
        return ap_found.full_name;
    }
}

/**
 * return dual band capability for a given AP model code
 */
function isApDualBand(model) {
    var ap_found = _.find(ap_models_array, ['model', model]);
    if (ap_found == null) {
        return false;
    } else {
        return ap_found.dual_band;
    }
}

/**
 * definition of the options for the Dwelltime analysis charts across the portal pages, where used
 */
var dwell_time_analysis_options = {
    chart: {
        type: 'area'
    },
    xAxis: {
        type: 'datetime'
    },
    yAxis: [{
    }, {
        opposite: true,
        min:      0,
        labels: {
            formatter: function() {
                return moment.duration(this.value, 'minutes').format('H [h] m [m]');
            }
        }
    }],
    tooltip: {
        shared:    true,
        useHTML:   true,
        borderWidth: 0,
        backgroundColor: "rgba(255,255,255,0)",
        shadow: false,
        formatter: function () {
            var tooltipcontent = '<b>' + moment(this.x).format("dddd, D MMMM YYYY") + '</b>';
            var tooltipfooter = '';
            var mySum          = 0;
            tooltipcontent    += '<table style="width: 100%;">';

            /**
             * we have to loop here as we don't yet know how many series we will have
             */
            $.each(this.points, function () {
                var symbol     = '■';
                var avg_suffix = '';
                if (this.series.name == 'average dwelltime') {
                    tooltipfooter += '<tr><td><br><span style="color:' + this.point.color + '">' + symbol + '</span> '
                                      + this.series.name + ':</td><td style="text-align: right;"><br>' + moment.duration(this.y, 'minutes').format('H [h] m [m]') + '</td></tr>';
                } else {
                    tooltipcontent += '<tr><td><span style="color:' + this.point.color + '">' + symbol + '</span> '
                                      + this.series.name + ':</td><td style="text-align: right;">' + this.y.toLocaleString() + '</td></tr>';
                    mySum += this.y;
                }

            });

            tooltipcontent += '<tr><td><b>Total:</b></td><td style="text-align: right;"><b>' + mySum.toLocaleString() + '</b><td></tr>';
            tooltipcontent += tooltipfooter;
            tooltipcontent += '</table>';
            return tooltipcontent;
        }
    },
    plotOptions: {
        line: {
            pointPlacement: 'between'
        },
        area: {
            stacking:  'normal',
            lineWidth: 1,
            marker: {
                lineWidth: 1
            }
        },
        column: {
            borderWidth: 0,
            stacking:    'normal'
        },
        series: {
            marker: {
                enabled: null,
                symbol:  'circle',
                radius:  2,
                states: {
                    hover: {
                        enabled: true
                    }
                }
            }
        }
    },
    legend: {
        enabled: true,
        reversed: true
    }
};

/**
 * CVS exporting function
 * source of this code:
 * http://stackoverflow.com/questions/14964035/how-to-export-javascript-array-info-to-csv-on-client-side/24922761#24922761
 */
function exportToCsv(filename, title, venue_name, rows) {
    //console.log(rows);
    var processRow = function (row) {
        var finalVal = '';
        for (var j = 0; j < row.length; j++) {
            var innerValue = row[j] === null ? '' : row[j].toString();
            if (row[j] instanceof Date) {
                innerValue = row[j].toLocaleString();
            };
            var result = innerValue.replace(/"/g, '""');
            if (result.search(/("|,|\n)/g) >= 0)
                result = '"' + result + '"';
            if (j > 0)
                finalVal += ',';
            finalVal += result;
        }
        return finalVal + '\n';
    };

    var csvFile = '';

    /**
     * add the report title and run time to the CSV contents
     */
    csvFile += title + '\n';
    csvFile += 'Venue: ' + venue_name + '\n';
    csvFile += 'report time: ' + moment().format("DD MMMM YYYY HH:mm") + '\n';

    for (var i = 0; i < rows.length; i++) {
        //console.log(rows[i]);
        if (rows[i].process == false) {
            csvFile += '\n\n' + rows[i].heading + '\n';
            csvFile += rows[i].data + '\n';
        } else {
            /**
             * add the heading to the CSV contents with formatting
             */
            csvFile += '\n\n' + rows[i].heading + '\n';

            /**
             * add the data rows to the CSV contents (table heading row and data rows)
             */
            for (var j = 0; j < rows[i].data.length; j++) {
                csvFile += processRow(rows[i].data[j]);
            }
        }
    }

    var blob = new Blob([csvFile], { type: 'text/csv;charset=utf-8;' });
    if (navigator.msSaveBlob) { // IE 10+
        navigator.msSaveBlob(blob, filename);
    } else {
        var link = document.createElement("a");
        if (link.download !== undefined) { // feature detection
            // Browsers that support HTML5 download attribute
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
}

/**
 * create and return new array, after adding missing timestamps
 * parameter bucketsize is in seconds
 */
function noGapsDataArray(data, bucketsize) {
    //console.log('data length: ' + data.length);
    if (data.length > 0) {

        var startDay = data[0][0],
        newData = [data[0]];

        for (i = 1; i < data.length; i++) {
            var diff = dateDiff(data[i - 1][0], data[i][0], bucketsize);
            var startDate = data[i - 1][0];
            if (diff > 1) {
                for (j = 0; j < diff - 1; j++) {
                    var fillDate = data[i - 1][0] + (bucketsize * 1000);
                    newData.push([fillDate, null]);
                }
            }
            newData.push(data[i]);
        }
        return newData;
    } else {
        return data;
    }
}

/**
 * helper function for newDataArray to find date differences
 */
function dateDiff(d1, d2, bucketsize) {
    return Math.floor((d2 - d1) / (bucketsize * 1000));
}

/**
 * Various tests to check for loaded jQuery plugins, and then set options etc. when necessary
 */

/**
 * What we wish to do in case the Highcharts library is loaded
 */
if (typeof Highcharts !== "undefined") {
    /**
     * general options for all Highcharts-based charts
     */
    var highchartsOptions = Highcharts.setOptions({
        global: {
            timezoneOffset: -2,  // The timezone offset in minutes, negative is "east" of UTC
            useUTC: false
        },
        lang: {
            loading: '<i class="fa fa-circle-o-notch fa-spin fa-2x"></i>'
        },
        plotOptions: {
            plotBackgroundColor: null,
            plotBorderWidth: null,
            plotShadow: false,
            series: {
                marker: {
                    enabled: false,
                    radius: 2,
                    states: {
                        hover: {
                            enabled: true
                        }
                    }
                },
                states: {
                    hover: {
                        enabled: true,
                        lineWidth: 2
                    }
                }
            },
            pie: {
                center: ['50%', '50%'],
                allowPointSelect: true,
                cursor: 'pointer',
                dataLabels: {
                    enabled: false,
                    distance: 10,
                    style: {
                        fontSize: '12px',
                        color: '#000000',
                        fontWeight: 'normal'
                    }
                },
                animation: {
                    duration: 500,
                    easing: 'easeInOutQuart'
                }
            },
            bar: {
                animation: {
                    duration: 500,
                    easing: 'easeInOutQuart'
                }
            },
            column: {
                animation: {
                    duration: 500,
                    easing: 'easeInOutQuart'
                }
            },
            line: {
                animation: {
                    duration: 500,
                    easing: 'easeInOutQuart'
                }
            },
            area: {
                stacking: 'normal',
                lineWidth: 1,
                animation: {
                    duration: 500,
                    easing: 'easeInOutQuart'
                }
            }
        },
        credits: {
            enabled: false
        },
        /**
         * definitions of colours to be used when not specifically set
         * green = 66B366
         */
        colors:   ["#e25826", "#132149", "#3E9C1A", "#232323", "#707676", "#a069db", "#DD686E"],
        title: false,
        subtitle: false,
        chart: {
            //backgroundColor: '#F9F9F9',
            backgroundColor: '#FFFFFF',
            reflow: true,
            spacingTop: 7,
            spacingBottom: 2,
            spacingLeft: 0,
            spacingRight: 0
        },
        xAxis: {
            tickWidth:0,
            gridLineColor: '#FAFAFA',
            gridLineWidth: 0
        },
        yAxis: {
            // floor: 0,
            title: {
                text: false
            }
        },
        tooltip: {
            style: {
                fontSize: '8pt'
            },
            borderColor: '#003166'
        },
        legend: {
            itemStyle: {
                fontSize: '12px',
                color: '#000000',
                fontWeight: 'normal'
            }
        }
    });
    /**
     * end of general options for Highcharts-based charts
     */

    /**
     * calculate weekends for plotbands in Highcharts charts, going back 1 year
     * may be not very efficient but this works...
     */
    var seriesEnd = moment().endOf('day');
    var seriesStart = seriesEnd - (3600000*24*365);
    var weekendsDaily = weekendAreasDaily(seriesStart, seriesEnd);
    var weekendsDaily4Columns = weekendAreasDaily4Columns(seriesStart, seriesEnd);
    var weekends = weekendAreas(seriesStart, seriesEnd);

    /**
     * this function is for calculating plotbands for the weekends for HIGH resolution (hourly stats) charts
     * now handles DST switches as well:-)
     */
    function weekendAreas(start, end) {
        var markings = [];
        var startOfWeekend = moment(end).startOf('week').subtract(50, 'weeks').subtract(2, 'days');
        do {
            markings.push({
                    color: '#F5F5F5', // the color of the weekend plotbands
                    from: Number(startOfWeekend.format('x')),
                    to: Number(startOfWeekend.add(2, 'days').format('x'))
            });
            startOfWeekend.add(5, 'days');
        } while (Number(startOfWeekend.format('x')) < end);

        return markings;
    }

    /**
     * this function is for calculating plotbands for the weekends for LOW resolution (daily stats) charts
     * now handles DST switches as well:-)
     */
    function weekendAreasDaily(start, end) {
        var markings = [];
        var startOfWeekend = moment(end).startOf('week').subtract(50, 'weeks').subtract(2, 'days');
        do {
            markings.push({
                    color: '#F5F5F5', // the color of the weekend plotbands
                    from: Number(startOfWeekend.format('x')),
                    to: Number(startOfWeekend.add(2, 'days').format('x'))
            });
            startOfWeekend.add(5, 'days');
        } while (Number(startOfWeekend.format('x')) < end);

        return markings;
    }

    /**
     * this function is for calculating plotbands for the weekends for LOW resolution (daily stats) charts !for column charts!
     * now handles DST switches as well:-)
     */
    function weekendAreasDaily4Columns(start, end) {
        var markings = [];
        var startOfWeekend = moment(end).startOf('week').subtract(50, 'weeks').subtract(2, 'days').subtract(12, 'hours');
        do {
            markings.push({
                    color: '#F5F5F5', // the color of the weekend plotbands
                    from: Number(startOfWeekend.format('x')),
                    to: Number(startOfWeekend.add(2, 'days').format('x'))
            });
            startOfWeekend.add(5, 'days');
        } while (Number(startOfWeekend.format('x')) < end);

        return markings;
    }

} else {
    //console.log('we dont have Highcharts');
}

/**
 * What we do when the Datatables library is loaded
 */
if ($.fn.DataTable) {
    /**
     * Datatables default configuration options:
     * - also disable default errors, allowing us to use custom alert using the flashToast function
     */
    $.extend( true, $.fn.DataTable.defaults, {
        deferRender: true,
        responsive: true,
        scrollX: true,
        autoWidth: false,
        language: {
            loadingRecords: '',
            processing: '<i class="fa fa-circle-o-notch fa-spin fa-2x" style="opacity: 0.5;"></i>'
        }
    });

    $.fn.dataTable.ext.errMode = 'none';

    /**
     * render function for Datatables column "model"
     * TODO:
     * create array of models with capabilities and full names (etc.) and use that here and elsewhere
     */
    function modelRenderFunction(data, type, full, meta) {
        if (type === "display" || type === 'filter') {
            return getApModelName(data);
        }

        return data;
    }

    /**
     * render function for Datatables column "hostname"
     */
    function hostnameRenderFunction(data, type, full, meta) {
        if (typeof data !== 'undefined') {
            if (type === "display") {
                return data;
            }

            return data;
        } else {
            if (typeof full.name === 'undefined') {
                return full.mac;
            } else {
                return full.name;
            }
        }
    }

    /**
     * render function for Datatables column "first_seen"
     */
    function first_seenRenderFunction(data, type, full, meta) {
        if (type === "display" || type === 'filter') {
            return moment(data * 1000).format("D MMM YYYY, HH:mm");
        }

        return data;
    }

    /**
     * render function for Datatables column "last_seen"
     */
    function last_seenRenderFunction(data, type, full, meta) {
        if (type === "display" || type === 'filter') {
            return moment(data * 1000).format("D MMM YYYY, HH:mm");
        }

        return data;
    }

    /**
     * render function for Datatables column "duration"
     */
    function durationRenderFunction(data, type, full, meta) {
        if (typeof data !== 'undefined') {
            if (type === "display") {
                return moment.duration(data, "seconds").format("d[d] h[u] m[m]");
            }

            return data;
        }

        return humanFileSize('0');
    }

    /**
     * render function for Datatables column "assoc_time"
     */
    function assoc_timeRenderFunction(data, type, full, meta) {
        if (typeof data === 'undefined' || !data) {
            return '0m';
        }

        if (type === "display" || type === 'filter') {
            var delta = moment().format("X") - data;
            return moment.duration(delta, "seconds").format("d[d] h[u] m[m]");
        }

        return moment().format("X") - data;
    }

    /**
     * render function for Datatables column "_uptime"
     */
    function up_timeRenderFunction(data, type, full, meta) {
        if (type === "display") {
            return moment.duration(data, "seconds").format("d[d] h[h] m[m]");
        }

        return data;
    }

    /**
     * render function for Datatables column "name" for access points
     */
    function ap_nameRenderFunction(data, type, full, meta) {
        if (typeof data === 'undefined' || !data) {
            return full.mac;
        }

        if (type === "display" || type === 'filter') {
            return data;
        }

        return data;
    }

    /**
     * render function for Datatables column "state"
     */
    function stateRenderFunction(data, type, full, meta) {
        if (type === 'display' || type === 'filter') {
            switch(data) {
                case 1:
                    if (full.locating === true) {
                        return ('<span class="label label-primary">flashing</span>');
                    }

                    return ('<span class="label label-success">OK</span>');
                    break;
                case 0:
                    return ('<span class="label label-danger">offline</span>');
                    break;
                case 2:
                    return ('<span class="label label-warning">pending adoption</span>');
                    break;
                case 4:
                    return ('<span class="label label-warning">provisioning</span>');
                    break;
                case 5:
                    return ('<span class="label label-warning">updating</span>');
                    break;
                case 6:
                    return ('<span class="label label-danger">unreachable</span>');
                    break;
                case 11:
                    return ('<span class="label label-warning">isolated</span>');
                    break;
                default:
                    return data;
            }
        }

        return data;
    }

    /**
     * render function for Datatables column "version"
     */
    function versionRenderFunction(data, type, full, meta) {
        if (type === "display" || type === 'filter') {
            if (typeof data === 'undefined' || !data) {
                return 'unknown';
            }

            return data;
        }

        return data;
    }

    /**
     * render function for Datatables column "rx_bytes"
     */
    function rx_bytesRenderFunction(data, type, full, meta) {
        if (type === "display") {
            if (typeof data === 'undefined' || !data || data === 0) {
                return '0 B';
            }

            return humanFileSize(data);
        }

        return data;
    }

    /**
     * render function for Datatables column "tx_bytes"
     */
    function tx_bytesRenderFunction(data, type, full, meta) {
        if (type === "display") {
            if (typeof data === 'undefined' || !data || data === 0) {
                return '0 B';
            }

            return humanFileSize(data);
        }

        return data;
    }

    /**
     * render function for Datatables column "is_guest"
     */
    function is_guestRenderFunction(data, type, full, meta) {
        if (type === "display" || type === 'filter') {
            if (!data) {
                data = '<div class="badge badge-primary">user</div>';
            } else {
                data = '<div class="badge badge-info">guest</div>';
            }
        }

        return data;
    }
} else {
    //console.log('we dont have DataTables');
}

/**
 * function to transform bytes to human readable output
 */
function humanFileSize(bytes, si) {
    var thresh = si ? 1000 : 1024;
    if (Math.abs(bytes) < thresh) {
        return bytes + ' B';
    }

    var units = si ? ['kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'] : ['KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'];
    var u = -1;
    do {
        bytes /= thresh;
        ++u;
    } while (Math.abs(bytes) >= thresh && u < units.length - 1);

    return bytes.toFixed(1) + ' ' + units[u];
}

/**
 * callback function for the ajax request to fetch active alarms count
 */
function onNavbarMetricsReceived(data) {
    console.log('refreshing navbar metrics (every 5 minutes)');

    /**
     * update active notification count
     */
    $('#notification_counter').html(data.active_alarms);
    $('#navbar_notification_counter').html(data.active_alarms);

    if (data.active_alarms > 0) {
        /**
         * we have active notifications so we change the badge style to danger
         */
        $('#notification_counter').removeClass('badge-primary').addClass('badge-danger');
    } else {
        /**
         * we do NOT have active notifications so we change the badge style to primary
         */
        $('#notification_counter').removeClass('badge-danger').addClass('badge-primary');
    }

    /**
     * update access points count
     */
    $('#span_aps_online').html(data.num_ap);
    $('#span_aps_offline').html(data.num_disconnected + data.num_disabled);
    $('#span_aps_total').html(data.num_adopted);

    if (data.num_disconnected > 0) {
        /**
         * we have offline APs so we change the badge style to danger
         */
        $('#span_aps_total').removeClass('badge-primary').addClass('badge-danger');
    } else {
        /**
         * we do NOT have offline APs so we change the badge style to primary
         */
        $('#span_aps_total').removeClass('badge-danger').addClass('badge-primary');
    }
}

/**
 * Force chrome to return to the top of the page before the next page reload
 */
$(window).on('unload', function() {
    $(window).scrollTop(0);
});

/**
 * Display UserFrosting alerts as Toasts
 * TODO:
 * - remove the duplicated toast code
 */
function flashToasts() {
    if (typeof toastr !== "undefined") {
        var url = site['uri']['public'] + "/alerts";
        return $.getJSON( url, {})
        .then(function( data ) {
            /**
             * now display the received alerts as toasts
             */

            console.log('toastr');

            if (data) {
                jQuery.each(data, function(alert_idx, alert_message) {
                    /**
                     * first we check whether there are active toasts and whether they are the same
                     * as the current in order to prevent duplicates
                     */
                    var $toastContainer = $('#toast-container');
                    if ($toastContainer.length > 0) {
                        var $errorToastr = $toastContainer.find('.toast-error');
                        if ($errorToastr.length > 0) {
                            var currentText = $errorToastr.find('.toast-message').text();
                            var areEqual = alert_message['message'].toUpperCase() === currentText.toUpperCase();
                            if (areEqual) {
                                //console.log('toastr messages are duplicates!!!');
                            } else {
                                console.log('toastr message not the same as previous');
                                if (alert_message['type'] == "success"){
                                    toastr['success'](alert_message['message']);
                                } else if (alert_message['type'] == "warning"){
                                    toastr['warning'](alert_message['message']);
                                } else  if (alert_message['type'] == "info"){
                                    toastr['info'](alert_message['message']);
                                } else if (alert_message['type'] == "danger"){
                                    toastr['error'](alert_message['message']);
                                }
                            }
                        }
                    } else {
                        /**
                         * no current toasts exist
                         */

                        if (alert_message['type'] == "success"){
                            toastr['success'](alert_message['message']);
                        } else if (alert_message['type'] == "warning"){
                            toastr['warning'](alert_message['message']);
                        } else  if (alert_message['type'] == "info"){
                            toastr['info'](alert_message['message']);
                        } else if (alert_message['type'] == "danger"){
                            toastr['error'](alert_message['message']);
                        }
                    }
                });
            }
        });
    }
}

/**
 * Display a modal form to update the session_expiry_time value for the current user
 */
function sessionExpiryModal(box_id) {
    var data = {
        box_id: box_id
    };

    // Delete any existing instance of the form with the same name
    if($('#' + box_id).length ) {
        $('#' + box_id).remove();
    }

    var url = site['uri']['public'] + "/forms/user/session_expiry_time/";

    // Fetch and render the form
    $.ajax({
        type:  'GET',
        data:  data,
        url:   url,
        cache: false
    })
    .fail(function(result) {
        // Display errors on failure
        $('#userfrosting-alerts').flashAlerts().done(function() {
        });
    })
    .done(function(result) {
        // Append the form as a modal dialog to the body
        $('body').append(result);
        $('#' + box_id).modal('show');

        // Initialize bootstrap switches
        var switches = $('#' + box_id + ' .bootstrapswitch');
        switches.bootstrapSwitch();
        switches.bootstrapSwitch('setSizeClass', 'switch-mini');

        // Link submission buttons
        ufFormSubmit(
            $('#' + box_id).find("form"),
            session_timeout_validators,
            $("#form-alerts"),
            function(data, statusText, jqXHR) {
                // on success
                $('#' + box_id).modal('hide');
                $('#userfrosting-alerts').flashAlerts().done(function() {
                    //
                });
            }
        );
    });
}

/**
 * functions to be executed upon jQuery document ready state
 */
$(document).ready(function() {
    /**
     * function to log off a user after staying on the same page for 30 minutes
     */
    window.setTimeout(function(){
        console.log('session expired after 30 minutes, logging off...');
        window.location.href = site.uri.public + '/account/logout';
    }, session_expiry_time*60*1000);

    /**
     * show the link to the modal which allows the user to change their session timeout,
     * hiding the link by default prevents the link from showing up on pages where this JS
     * file isn't loaded
     */
    $('#session_timeout_li').show();

    /**
     * function to enable tooltips
     */
    $(function () {
        //$('[data-toggle="tooltip"]').tooltip();
        $('[data-toggle="tooltip"]').tooltip({ container: 'body' });
    })

    /**
     * code to detect venue switch request from top nav bar
     */
    $('#venue_selection li.venue_id').click(function(e) {
        e.preventDefault();
        var current_venue = $("meta[name=current_venue]").attr("content");
        var base_url = $("meta[name=base_url]").attr("content");

        if ($(this).attr('id').replace('venue_','') !== current_venue) {
            // what happens when a switch of venue is requested
            var requested_venue = $(this).attr('id').replace('venue_','');
            var requested_venue_name = $(this).find('a').text();
            console.log('requested switch to venue: ' + requested_venue_name);

            /**
             * we create a toastr message to inform the user
             */
            toastr['success']('Switching to venue: ' + requested_venue_name);

            /**
             * ajax function to request switch of primary_venue for the current user
             * TODO: check whether new venue has controller_id set, if not push user to /dashboard
             */
            $.ajax({
                url: base_url + '/users/switch_venue/' + requested_venue + '/' + current_venue,
                type: 'GET',
                dataType: 'json',
                /**
                 * to satisfy Firefox we define the success function here instead of as a separate function
                 */
                success: function (data) {
                    console.log('venue switch approved and done!');
                    if (data === 'OK') {
                        /**
                         * Reload the current page on success
                         */
                        window.location.reload(true);
                    } else {
                        /**
                         * new venue is not fully configured so we send user to path returned with data
                         */
                        window.location.replace(base_url + '/' + data);
                    }
                },
                error: flashToasts
            });
        } else {
            // what we do when the current venue is selected
        }
    });

    /**
     * code to collapse expand panel bodies
     *
     * NOTE:
     * not required for metronic based templates
     */
    $(document).on('click', '.panel-heading span.clickable', function(e){
        var $this = $(this);
        if(!$this.hasClass('panel-collapsed')) {
            $this.parents('.panel').find('.panel-content').slideUp();
            $this.addClass('panel-collapsed');
            $this.find('i').removeClass('fa-chevron-up').addClass('fa-chevron-down');
        } else {
            $this.parents('.panel').find('.panel-content').slideDown();
            $this.removeClass('panel-collapsed');
            $this.find('i').removeClass('fa-chevron-down').addClass('fa-chevron-up');
        }
    })

    /**
     * only when we have loaded Datatables do we resize them
     */
    if (!$.fn.DataTable) {
        //
    } else {
        /**
         * table redraw on resize of window and collapse of sidebar
         */
        $(window).resize(function(){
            $.fn.DataTable.tables( {visible: true, api: true} ).columns.adjust();
        });
    }

    /**
     * force a resize event after page load
     */
    window.dispatchEvent(new Event('resize'));
});