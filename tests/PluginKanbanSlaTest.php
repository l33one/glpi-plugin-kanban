<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the private SLA calculation logic via reflection.
 * These tests exercise pure logic without needing the database.
 */
class PluginKanbanSlaTest extends TestCase
{
    private function calculateSla(array $ticket): array
    {
        $method = new ReflectionMethod(PluginKanbanKanban::class, 'calculateSlaProgress');
        $method->setAccessible(true);
        return $method->invoke(null, $ticket);
    }

    public function testNoSlaReturnsNoSlaStatus(): void
    {
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '',
        ]);
        $this->assertSame(0, $result['percent']);
        $this->assertSame('success', $result['color']);
        $this->assertSame('no_sla', $result['status']);
    }

    public function testOverdueSlaReturns100PercentDanger(): void
    {
        $_SESSION['glpi_currenttime'] = '2026-08-02 12:00:00';
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '2026-08-02 10:00:00',
        ]);
        $this->assertSame(100, $result['percent']);
        $this->assertSame('danger', $result['color']);
        $this->assertSame('overdue', $result['status']);
    }

    public function testExactlyHalfElapsedReturns50Percent(): void
    {
        $_SESSION['glpi_currenttime'] = '2026-08-02 10:00:00';
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '2026-08-03 10:00:00',
        ]);
        $this->assertEquals(50, $result['percent']);
        $this->assertSame('success', $result['color']);
        $this->assertSame('active', $result['status']);
    }

    public function testNinetyPercentElapsedIsWarning(): void
    {
        // 43h elapsed of 48h total = ~90%
        $_SESSION['glpi_currenttime'] = '2026-08-03 05:00:00';
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '2026-08-03 10:00:00',
        ]);
        $this->assertEquals(90, $result['percent']);
        $this->assertSame('warning', $result['color']);
        $this->assertSame('active', $result['status']);
    }

    public function testDeadlineBeforeCreationIsImmediatelyOverdue(): void
    {
        $_SESSION['glpi_currenttime'] = '2026-08-02 12:00:00';
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-03 10:00:00',
            'time_to_resolve'  => '2026-08-02 10:00:00',
        ]);
        $this->assertSame(100, $result['percent']);
        $this->assertSame('danger', $result['color']);
        $this->assertSame('overdue', $result['status']);
    }

    public function testNegativeElapsedTimeClampedToZero(): void
    {
        $_SESSION['glpi_currenttime'] = '2026-07-31 10:00:00'; // Before creation
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '2026-08-03 10:00:00',
        ]);
        $this->assertEquals(0, $result['percent']);
        $this->assertSame('success', $result['color']);
        $this->assertSame('active', $result['status']);
    }

    public function testSolvedTicketFreezesSlaAtSolvedDate(): void
    {
        // 96h SLA total, 24h elapsed at resolution. Without freezing the clock
        // would show 50% (48h elapsed at "now").
        $_SESSION['glpi_currenttime'] = '2026-08-03 10:00:00';
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '2026-08-05 10:00:00',
            'status'           => Ticket::SOLVED,
            'solvedate'        => '2026-08-02 10:00:00',
        ]);
        $this->assertEquals(25, $result['percent']);
        $this->assertSame('success', $result['color']);
        $this->assertSame('active', $result['status']);
        $this->assertTrue($result['frozen'], 'Solved tickets must freeze the SLA clock');
        $this->assertSame('2026-08-02 10:00:00', $result['frozen_at']);
    }

    public function testClosedTicketFreezesSlaAtSolvedDate(): void
    {
        $_SESSION['glpi_currenttime'] = '2026-08-03 10:00:00';
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '2026-08-05 10:00:00',
            'status'           => Ticket::CLOSED,
            'solvedate'        => '2026-08-02 10:00:00',
        ]);
        $this->assertEquals(25, $result['percent']);
        $this->assertTrue($result['frozen']);
        $this->assertSame('2026-08-02 10:00:00', $result['frozen_at']);
    }

    public function testSolvedTicketWithoutSolvedDateFallsBackToDateMod(): void
    {
        $_SESSION['glpi_currenttime'] = '2026-08-03 10:00:00';
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '2026-08-05 10:00:00',
            'status'           => Ticket::SOLVED,
            'solvedate'        => '',
            'date_mod'         => '2026-08-02 10:00:00',
        ]);
        $this->assertEquals(25, $result['percent']);
        $this->assertTrue($result['frozen']);
        $this->assertSame('2026-08-02 10:00:00', $result['frozen_at']);
    }

    public function testPendingTicketFreezesSlaAtBeginWaitingDate(): void
    {
        // 96h SLA total. The ticket went pending 8h after opening, so the
        // clock must stop there (~8%) even though "now" is 48h later (50%).
        $_SESSION['glpi_currenttime'] = '2026-08-03 10:00:00';
        $result = $this->calculateSla([
            'date_creation'       => '2026-08-01 10:00:00',
            'time_to_resolve'     => '2026-08-05 10:00:00',
            'status'              => Ticket::WAITING,
            'begin_waiting_date'  => '2026-08-01 18:00:00',
        ]);
        $this->assertEquals(8, $result['percent']);
        $this->assertSame('success', $result['color']);
        $this->assertSame('active', $result['status']);
        $this->assertTrue($result['frozen'], 'Pending tickets must pause the SLA clock');
        $this->assertSame('2026-08-01 18:00:00', $result['frozen_at']);
    }

    public function testPendingTicketWithoutWaitingDateKeepsCounting(): void
    {
        // No begin_waiting_date available: fall back to the current time.
        $_SESSION['glpi_currenttime'] = '2026-08-03 10:00:00';
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '2026-08-05 10:00:00',
            'status'           => Ticket::WAITING,
        ]);
        $this->assertEquals(50, $result['percent']);
        $this->assertFalse($result['frozen']);
    }

    public function testActiveTicketKeepsCountingInRealTime(): void
    {
        $_SESSION['glpi_currenttime'] = '2026-08-03 10:00:00';
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '2026-08-05 10:00:00',
            'status'           => Ticket::ASSIGNED,
        ]);
        $this->assertEquals(50, $result['percent']);
        $this->assertFalse($result['frozen'], 'Active tickets must keep the SLA clock running');
    }

    public function testOverdueSolvedTicketIsFrozenAndOverdue(): void
    {
        $_SESSION['glpi_currenttime'] = '2026-08-03 10:00:00';
        $result = $this->calculateSla([
            'date_creation'    => '2026-08-01 10:00:00',
            'time_to_resolve'  => '2026-08-02 10:00:00',
            'status'           => Ticket::SOLVED,
            'solvedate'        => '2026-08-02 12:00:00',
        ]);
        $this->assertSame(100, $result['percent']);
        $this->assertSame('danger', $result['color']);
        $this->assertSame('overdue', $result['status']);
        $this->assertTrue($result['frozen']);
    }
}
