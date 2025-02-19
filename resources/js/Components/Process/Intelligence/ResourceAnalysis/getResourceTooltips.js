const getResourceTooltips = (resource, status) => {
  const baseTooltips = {
    status: {
      critical: 'Resource is critically overutilized. Immediate action required.',
      high: 'Resource utilization is approaching critical levels. Consider preventive actions.',
      medium: 'Resource utilization is elevated but manageable.',
      normal: 'Resource utilization is within normal operating range.'
    },
    trend: {
      increasing: 'Utilization is projected to increase in the next hour based on current patterns.',
      decreasing: 'Utilization is projected to decrease in the next hour based on current patterns.'
    }
  };

  const resourceSpecificTooltips = {
    nurses: {
      utilization: 'Current nursing staff allocation relative to required coverage.',
      actions: {
        request: 'Request additional nursing staff from the staffing pool or agency resources.',
        adjust: 'Modify current shift assignments to optimize coverage.'
      }
    },
    physicians: {
      utilization: 'Current physician coverage relative to service requirements.',
      actions: {
        page: 'Send immediate alert to on-call physician for urgent support.',
        view: 'Review detailed physician coverage schedule and availability.'
      }
    },
    rooms: {
      utilization: 'Current room occupancy relative to total capacity.',
      actions: {
        view: 'View detailed status of all rooms including cleaning and preparation status.',
        expedite: 'Prioritize room turnover to increase available capacity.'
      }
    }
  };

  return {
    status: baseTooltips.status[status.status],
    trend: baseTooltips.trend[status.trend.label.toLowerCase()],
    utilization: resourceSpecificTooltips[resource.key].utilization,
    actions: resourceSpecificTooltips[resource.key].actions
  };
}

export default getResourceTooltips;
