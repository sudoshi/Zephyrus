import React from 'react';
import MetricCard from './MetricCard';
import { syntheticData } from '../../mock-data/dashboard';

const LastMonthSection = () => {
    const data = syntheticData.lastMonth;

    return (
        <section className="mb-8">
            <h2 className="text-xl font-bold mb-4">Last Month</h2>
            <div className="flex flex-wrap gap-4">
                <MetricCard 
                    title="First Case On Time Starts"
                    value={data.firstCaseOnTime.value}
                    trend={data.firstCaseOnTime.trend}
                    previousValue={`${data.firstCaseOnTime.previousValue}%`}
                    date={data.firstCaseOnTime.date}
                    info="Displays the percentage of cases that were on time and took place within the previous calendar month."
                />
                <MetricCard 
                    title="Average Room Turnover"
                    value={data.avgTurnover.value}
                    trend={data.avgTurnover.trend}
                    previousValue={`${data.avgTurnover.previousValue} min`}
                    date={data.avgTurnover.date}
                    showAsPercentage={false}
                    info="Displays the average room and procedure turnover for cases that took place within the previous calendar month."
                />
                <MetricCard 
                    title="Case Length Accuracy"
                    value={data.caseLengthAccuracy.value}
                    trend={data.caseLengthAccuracy.trend}
                    previousValue={`${data.caseLengthAccuracy.previousValue}%`}
                    date={data.caseLengthAccuracy.date}
                    info="Displays the percentage of cases performed within the previous calendar month that were accurately scheduled."
                />
                <MetricCard 
                    title="Performed Cases"
                    value={data.performedCases.value}
                    trend={data.performedCases.trend}
                    previousValue={data.performedCases.previousValue}
                    date={data.performedCases.date}
                    showAsPercentage={false}
                    info="Displays the volume of cases that were performed within the previous calendar month."
                />
                <MetricCard 
                    title="DoS Cancellations"
                    value={data.doSCancellations.value}
                    trend={data.doSCancellations.trend}
                    previousValue={data.doSCancellations.previousValue}
                    date={data.doSCancellations.date}
                    showAsPercentage={false}
                    info="Displays the number of cases from the previous calendar month that were canceled, rescheduled, or for which no procedure was performed on the day of surgery."
                />
                <MetricCard 
                    title="Block Utilization"
                    value={data.blockUtilization.value}
                    trend={data.blockUtilization.trend}
                    previousValue={`${data.blockUtilization.previousValue}%`}
                    date={data.blockUtilization.date}
                    info="Displays a high-level summary of OR block utilization data for your OR locations. The data is only refreshed when utilization batch jobs are run."
                />
                <MetricCard 
                    title="Primetime Utilization"
                    value={data.primetimeUtilization.staffed}
                    trend={data.primetimeUtilization.trend}
                    previousValue={`${data.primetimeUtilization.unstaffed}%`}
                    date={data.primetimeUtilization.date}
                    info="Displays the total in-room and setup/cleanup minutes over the available primetime minutes from the previous calendar month. The Primetime Room Utilization percentage includes rooms closed due to staff being unavailable, and cases only count against open available time for the room they are performed in. The Primetime Staffed Room Utilization percentage does not include rooms closed due to staff being unavailable in the denominator, but cases will 'float' across rooms to be in the numerator of the percentage."
                />
            </div>
        </section>
    );
};

export default LastMonthSection;
