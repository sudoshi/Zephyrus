import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import DashboardLayout from '@/Components/Dashboard/DashboardLayout';
import PageContentLayout from '@/Components/Common/PageContentLayout';
import { Section, MetricGrid, Panel, metric } from '@/Components/system';
import RoomStatusCard from '@/Components/RoomStatus/RoomStatusCard';
import RoomDetailsModal from '@/Components/RoomStatus/RoomDetailsModal';

const RoomStatus = ({ roomStatus = null }) => {
    const [selectedLocation, setSelectedLocation] = useState('all');
    const [selectedRoom, setSelectedRoom] = useState(null);

    // Mock data - replace with actual data from your backend
    const mockRooms = Array.from({ length: 12 }).map((_, index) => ({
        number: index + 1,
        status: index % 3 === 0 ? 'in_progress' : index % 3 === 1 ? 'turnover' : 'available',
        currentCase: index % 3 === 0 ? {
            patient: 'John Doe',
            procedure: 'Total Hip Replacement',
            provider: 'Dr. Smith',
            startTime: '09:30',
            expectedEndTime: '11:00',
            expectedDuration: 90,
            elapsed: 45,
            staff: [
                { name: 'Dr. Smith', role: 'Surgeon' },
                { name: 'Jane Wilson', role: 'Anesthesiologist' },
                { name: 'Mary Johnson', role: 'Scrub Nurse' }
            ],
            resources: [
                { name: 'OR Table', status: 'in_progress' },
                { name: 'Anesthesia Machine', status: 'in_progress' },
                { name: 'Surgical Tools', status: 'in_progress' }
            ],
            notes: 'Patient has latex allergy',
            alerts: index === 3 ? ['Blood pressure elevated', 'Medication due in 15 minutes'] : []
        } : null,
        nextCase: index % 3 === 1 ? {
            startTime: '10:30',
            procedure: 'Knee Arthroscopy'
        } : null,
        timeRemaining: index % 3 === 0 ? 45 : null,
        turnoverTime: index % 3 === 1 ? 15 : null
    }));

    // Live data from the controller (seeded prod.* tables); fall back to the
    // static mock so the board still renders if the prop is absent.
    const rooms = (roomStatus?.rooms?.length ? roomStatus.rooms : mockRooms);

    const stats = {
        total: rooms.length,
        inUse: rooms.filter(r => r.status === 'in_progress' || r.status === 'delayed').length,
        available: rooms.filter(r => r.status === 'available').length,
        turnover: rooms.filter(r => r.status === 'turnover').length
    };

    const occupancyPct = stats.total > 0 ? Math.round((stats.inUse / stats.total) * 100) : 0;
    const availabilityPct = stats.total > 0 ? Math.round((stats.available / stats.total) * 100) : 0;

    // Board summary KPIs — counts derived live from the room list. No underlying
    // time series exists, so no sparkline is fabricated; occupancy/availability
    // shares carry as captions, mirroring the old MetricsCard trend labels.
    const roomMetrics = [
        metric({
            key: 'total-rooms', label: 'Total Rooms', value: stats.total, display: String(stats.total),
            status: 'neutral', definition: 'Operating rooms on the board.',
        }),
        metric({
            key: 'in-use', label: 'In Use', value: stats.inUse, display: String(stats.inUse),
            status: 'info', caption: `${occupancyPct}% occupancy`,
            definition: 'Rooms with a case in progress (or delayed).',
        }),
        metric({
            key: 'available', label: 'Available', value: stats.available, display: String(stats.available),
            status: 'success', caption: `${availabilityPct}% availability`,
            definition: 'Rooms clean and ready for the next case.',
        }),
        metric({
            key: 'turnovers', label: 'Turnovers', value: stats.turnover, display: String(stats.turnover),
            status: 'warning', definition: 'Rooms being cleaned/reset between cases.',
        }),
    ];

    return (
        <DashboardLayout>
            <Head title="Room Status - ZephyrusOR" />
            <PageContentLayout
                title="Room Status"
                subtitle="Monitor real-time operating room status and activities"
            >
                <div className="flex flex-col gap-5">
                    {/* Filter Panel */}
                    <Panel>
                        <div className="p-4">
                            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                <div className="flex items-center space-x-4">
                                    <div className="relative">
                                        <select
                                            value={selectedLocation}
                                            onChange={(e) => setSelectedLocation(e.target.value)}
                                            className="text-sm border-healthcare-border dark:border-healthcare-border-dark rounded-md pl-8 pr-4 py-2 appearance-none bg-healthcare-surface dark:bg-healthcare-surface-dark hover:border-healthcare-info dark:hover:border-healthcare-info-dark transition-colors duration-300"
                                        >
                                            <option value="all">All Locations</option>
                                            <option value="main">Main OR</option>
                                            <option value="endo">Endoscopy</option>
                                            <option value="cardiac">Cardiac OR</option>
                                        </select>
                                        <Icon
                                            icon="heroicons:building-office"
                                            className="absolute left-2 top-1/2 transform -translate-y-1/2 w-4 h-4 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark transition-colors duration-300"
                                        />
                                    </div>
                                </div>
                                <div className="flex items-center space-x-4">
                                    <button className="inline-flex items-center text-sm text-healthcare-info dark:text-healthcare-info-dark hover:text-healthcare-info-dark dark:hover:text-healthcare-info font-medium transition-colors duration-300">
                                        <Icon icon="heroicons:arrow-path" className="w-4 h-4 mr-1" />
                                        Refresh
                                    </button>
                                </div>
                            </div>
                        </div>
                    </Panel>

                    {/* Metrics Grid */}
                    <Section title="Board summary" icon="heroicons:building-office-2"
                             summary={`${stats.inUse} in use · ${stats.available} available across ${stats.total} rooms`}>
                        <MetricGrid metrics={roomMetrics} />
                    </Section>

                    {/* Room Status Grid */}
                    <Section title="Room Status Board" icon="heroicons:squares-2x2"
                             summary="Live status per operating room — click for detail">
                        <Panel>
                            <div className="p-4">
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                                    {rooms.map((room) => (
                                        <RoomStatusCard
                                            key={room.number}
                                            room={room}
                                            onClick={() => setSelectedRoom(room)}
                                        />
                                    ))}
                                </div>
                            </div>
                        </Panel>
                    </Section>
                </div>

                {/* Room Details Modal */}
                {selectedRoom && (
                    <RoomDetailsModal
                        room={selectedRoom}
                        onClose={() => setSelectedRoom(null)}
                    />
                )}
            </PageContentLayout>
        </DashboardLayout>
    );
};

export default RoomStatus;
