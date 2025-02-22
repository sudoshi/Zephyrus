// Mock data generator for discharge priorities
const generateMockDischargeData = () => {
  // Constants for generating realistic data
  const HOSPITALS = [
    'Marlton Hospital',
    'Mount Holly Hospital',
    'Our Lady of Lourdes Hospital',
    'Voorhees Hospital',
    'Willingboro Hospital'
  ];

  const UNITS = [
    'Medical/Surgical',
    'Telemetry',
    'ICU',
    'CCU',
    'Oncology',
    'Orthopedics',
    'Neurology',
    'Rehabilitation'
  ];

  const SERVICES = [
    'Internal Medicine',
    'Cardiology',
    'Neurology',
    'Oncology',
    'Orthopedics',
    'General Surgery',
    'Pulmonology',
    'Rehabilitation'
  ];

  const NAMES = [
    'Smith', 'Johnson', 'Williams', 'Brown', 'Jones',
    'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
    'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson',
    'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin'
  ];

  const FIRST_NAMES = [
    'James', 'John', 'Robert', 'Michael', 'William',
    'David', 'Richard', 'Joseph', 'Thomas', 'Charles',
    'Mary', 'Patricia', 'Jennifer', 'Linda', 'Elizabeth',
    'Barbara', 'Susan', 'Jessica', 'Sarah', 'Karen'
  ];

  // Helper functions
  const randomFromArray = (arr) => arr[Math.floor(Math.random() * arr.length)];
  const randomInt = (min, max) => Math.floor(Math.random() * (max - min + 1)) + min;
  
  const generatePatient = (id, priorityLevel) => {
    const hospital = randomFromArray(HOSPITALS);
    const unit = randomFromArray(UNITS);
    const service = randomFromArray(SERVICES);
    const currentLOS = randomInt(3, 15);
    const expectedLOS = currentLOS + randomInt(-2, 3);
    
    // Calculate unit capacity based on priority level
    let unitCapacity;
    switch(priorityLevel) {
      case 1:
        unitCapacity = randomInt(90, 100);
        break;
      case 2:
        unitCapacity = randomInt(70, 85);
        break;
      case 3:
        unitCapacity = randomInt(80, 90);
        break;
      case 4:
        unitCapacity = randomInt(60, 79);
        break;
      default:
        unitCapacity = randomInt(60, 100);
    }

    // Determine improvement status based on priority level
    let improvement;
    if (priorityLevel === 1) {
      improvement = Math.random() > 0.3 ? 'Rapid' : 'Steady';
    } else if (priorityLevel === 2) {
      improvement = 'Rapid';
    } else {
      improvement = randomFromArray(['Rapid', 'Steady', 'Slow']);
    }

    // Determine risk level based on improvement and priority
    let risk;
    if (improvement === 'Rapid') {
      risk = 'Low';
    } else if (improvement === 'Steady') {
      risk = randomFromArray(['Low', 'Medium']);
    } else {
      risk = randomFromArray(['Medium', 'High']);
    }

    return {
      id,
      name: `${randomFromArray(FIRST_NAMES)} ${randomFromArray(NAMES)}`,
      age: randomInt(25, 85),
      hospital,
      unit,
      service,
      los: currentLOS,
      expectedLos: expectedLOS,
      unitCapacity: `${unitCapacity}%`,
      improvement,
      risk,
      priority: priorityLevel
    };
  };

  // Generate patients for each priority level
  const priority1 = Array(15).fill(null).map((_, i) => generatePatient(i + 1, 1));
  const priority2 = Array(10).fill(null).map((_, i) => generatePatient(i + 16, 2));
  const priority3 = Array(10).fill(null).map((_, i) => generatePatient(i + 26, 3));
  const priority4 = Array(5).fill(null).map((_, i) => generatePatient(i + 36, 4));

  return {
    priority1,
    priority2,
    priority3,
    priority4,
    hospitals: HOSPITALS,
    units: UNITS,
    services: SERVICES
  };
};

export default generateMockDischargeData;
