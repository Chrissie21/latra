<?php
namespace Latra\Application\AI;

/** Fixed query identities; none can carry model-provided SQL. */
enum AssistantReadQuery: string
{
    case Vehicle1 = 'vehicle.1';
    case SearchVehicle1 = 'search_vehicle.1';
    case GetVehicleDetails1 = 'get_vehicle_details.1';
    case GetVehicleStatus1 = 'get_vehicle_status.1';
    case GetVehicleLocation1 = 'get_vehicle_location.1';
    case GetVehicleLocation2 = 'get_vehicle_location.2';
    case GetVehicleEvents1 = 'get_vehicle_events.1';
    case GetVehicleEvents2 = 'get_vehicle_events.2';
    case GetVehicleSubmissionStatus1 = 'get_vehicle_submission_status.1';
    case GetVehicleSubmissionStatus2 = 'get_vehicle_submission_status.2';
    case GetVehicleSubmissionStatus3 = 'get_vehicle_submission_status.3';
    case GetRecentLatraSubmissions1 = 'get_recent_latra_submissions.1';
    case GetFailedLatraSubmissions1 = 'get_failed_latra_submissions.1';
    case GetFailedLatraSubmissions2 = 'get_failed_latra_submissions.2';
    case GetLatraSubmissionStatistics1 = 'get_latra_submission_statistics.1';
    case GetLatraSubmissionStatistics2 = 'get_latra_submission_statistics.2';
    case GetLatraSubmissionStatistics3 = 'get_latra_submission_statistics.3';
    case GetActivitySubmissionStatus1 = 'get_activity_submission_status.1';
    case GetRecentEvents1 = 'get_recent_events.1';
    case GetEventStatistics1 = 'get_event_statistics.1';
    case GetEmergencyEvents1 = 'get_emergency_events.1';
    case GetSchedulerStatus1 = 'get_scheduler_status.1';
    case GetSchedulerStatus2 = 'get_scheduler_status.2';
    case GetSchedulerRuns1 = 'get_scheduler_runs.1';
    case GetFailedSchedulerRuns1 = 'get_failed_scheduler_runs.1';
    case GetFailedSchedulerRuns2 = 'get_failed_scheduler_runs.2';
    case GetWorkerStatus1 = 'get_worker_status.1';
    case GetWorkerStatus2 = 'get_worker_status.2';
    case GetWorkerStatus3 = 'get_worker_status.3';
    case GetAutomationRuns1 = 'get_automation_runs.1';
    case GetFailedAutomationRuns1 = 'get_failed_automation_runs.1';
    case GetFailedAutomationRuns2 = 'get_failed_automation_runs.2';
    case GetRunningAutomationRuns1 = 'get_running_automation_runs.1';
    case GetAutomationStatistics1 = 'get_automation_statistics.1';
    case GetSystemHealth1 = 'get_system_health.1';
    case GetSystemHealth2 = 'get_system_health.2';
    case GetSystemHealth3 = 'get_system_health.3';
    case GetSystemHealth4 = 'get_system_health.4';
    case GetSystemHealth5 = 'get_system_health.5';
    case GetOutboxStatus1 = 'get_outbox_status.1';
    case GetOutboxStatus2 = 'get_outbox_status.2';
    case GetGpsHealth1 = 'get_gps_health.1';
    case GetDeviceHealth1 = 'get_device_health.1';
    case GetDeviceHealth2 = 'get_device_health.2';
    case GetComplianceSummary1 = 'get_compliance_summary.1';
    case GetComplianceSummary2 = 'get_compliance_summary.2';
    case GetComplianceSummary3 = 'get_compliance_summary.3';
    case GetComplianceSummary4 = 'get_compliance_summary.4';
}
