<?php
declare(strict_types=1);

namespace App\Http\Controllers;

defined('NX_ROOT') || exit;

use App\Core\Request;
use App\Core\Response;
use App\Services\RecordService;

final class CalendarController extends Controller
{
    public function index(Request $r): Response
    {
        return $this->view('calendar', ['title' => 'Calendar']);
    }

    /** Events, task due dates, leave and invoice due dates between start and end (local dates). */
    public function feed(Request $r): Response
    {
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r->query['start'] ?? '')) ? $r->query['start'] : date('Y-m-01');
        $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r->query['end'] ?? '')) ? $r->query['end'] : date('Y-m-t');
        $mine = ($r->query['mine'] ?? '') === '1';
        $layers = array_filter(explode(',', (string) ($r->query['layers'] ?? 'events,tasks,leave,invoices,deals')));
        $out = [];
        $base = fn (array $extra) => ['per_page' => 500, 'max_per_page' => 500, 'filters' => array_merge($extra, $mine ? [['field' => 'assigned_to', 'op' => 'me']] : [])];

        if (in_array('events', $layers, true) && can('events', 'view')) {
            foreach (RecordService::for('events')->list($base([['field' => 'starts_at', 'op' => 'between', 'value' => $start, 'value2' => $end]]))['rows'] as $e) {
                $out[] = ['id' => 'e' . $e['id'], 'title' => $e['title'], 'start' => to_local_input($e['starts_at']), 'end' => to_local_input($e['ends_at']), 'allDay' => (bool) $e['all_day'], 'type' => 'event', 'color' => 'indigo', 'url' => url('/r/events/' . $e['id']), 'meta' => $e['type'] . ($e['location'] ? ' · ' . $e['location'] : '')];
            }
        }
        if (in_array('tasks', $layers, true) && can('tasks', 'view')) {
            foreach (RecordService::for('tasks')->list($base([['field' => 'due_date', 'op' => 'between', 'value' => $start, 'value2' => $end]]))['rows'] as $t) {
                $out[] = ['id' => 't' . $t['id'], 'title' => $t['title'], 'start' => $t['due_date'], 'allDay' => true, 'type' => 'task', 'color' => $t['status'] === 'Done' ? 'green' : ($t['due_date'] < date('Y-m-d') ? 'red' : 'amber'), 'url' => url('/r/tasks/' . $t['id']), 'meta' => 'Task · ' . $t['status'], 'done' => $t['status'] === 'Done'];
            }
        }
        if (in_array('leave', $layers, true) && can('leave_requests', 'view')) {
            foreach (RecordService::for('leave_requests')->list(['per_page' => 500, 'max_per_page' => 500, 'filters' => [['field' => 'start_date', 'op' => 'lte', 'value' => $end], ['field' => 'end_date', 'op' => 'gte', 'value' => $start], ['field' => 'status', 'op' => 'in', 'value' => ['Approved', 'Pending']]]])['rows'] as $l) {
                $out[] = ['id' => 'l' . $l['id'], 'title' => ($l['_labels']['employee_id'] ?? 'Employee') . ' — ' . $l['type'], 'start' => $l['start_date'], 'end' => $l['end_date'], 'allDay' => true, 'type' => 'leave', 'color' => $l['status'] === 'Approved' ? 'teal' : 'slate', 'url' => url('/r/leave_requests/' . $l['id']), 'meta' => 'Leave · ' . $l['status']];
            }
        }
        if (in_array('invoices', $layers, true) && can('invoices', 'view')) {
            foreach (RecordService::for('invoices')->list($base([['field' => 'due_date', 'op' => 'between', 'value' => $start, 'value2' => $end], ['field' => 'status', 'op' => 'in', 'value' => ['Sent', 'Partially Paid', 'Overdue']]]))['rows'] as $i) {
                $out[] = ['id' => 'i' . $i['id'], 'title' => $i['number'] . ' due · ' . money($i['balance'], $i['currency']), 'start' => $i['due_date'], 'allDay' => true, 'type' => 'invoice', 'color' => 'rose', 'url' => url('/r/invoices/' . $i['id']), 'meta' => $i['_labels']['customer_id'] ?? ''];
            }
        }
        if (in_array('deals', $layers, true) && can('deals', 'view')) {
            foreach (RecordService::for('deals')->list($base([['field' => 'expected_close', 'op' => 'between', 'value' => $start, 'value2' => $end], ['field' => 'status', 'op' => 'eq', 'value' => 'open']]))['rows'] as $d) {
                $out[] = ['id' => 'd' . $d['id'], 'title' => $d['name'] . ' · close', 'start' => $d['expected_close'], 'allDay' => true, 'type' => 'deal', 'color' => 'violet', 'url' => url('/r/deals/' . $d['id']), 'meta' => money($d['amount'], $d['currency'])];
            }
        }
        return $this->json(['items' => $out]);
    }
}
