import React, { useState, useMemo } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { ChevronDown, ChevronUp } from 'lucide-react';

const ITEMS_PER_PAGE = 10;

// Filter Controls Component
const FilterControls = ({ filters, onFilterChange }) => {
  return (
    <div className="flex gap-4 mb-4">
      <Input
        placeholder="Search..."
        value={filters.search}
        onChange={(e) => onFilterChange('search', e.target.value)}
        className="max-w-xs"
      />
      <Select
        value={filters.site}
        onValueChange={(value) => onFilterChange('site', value)}
      >
        <SelectTrigger className="w-48">
          <SelectValue placeholder="Select Site" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">All Sites</SelectItem>
          <SelectItem value="MARH IR">MARH IR</SelectItem>
          <SelectItem value="MARH OR">MARH OR</SelectItem>
        </SelectContent>
      </Select>
    </div>
  );
};

const TablePagination = ({ currentPage, totalPages, onPageChange }) => (
  <div className="flex items-center justify-between px-2 py-3 bg-white border-t">
    <div className="flex justify-between w-full">
      <span className="text-sm text-gray-700">
        Page {currentPage} of {totalPages}
      </span>
      <div className="space-x-2">
        <button
          onClick={() => onPageChange(currentPage - 1)}
          disabled={currentPage === 1}
          className="px-3 py-1 text-sm border rounded hover:bg-gray-50 disabled:opacity-50"
        >
          Previous
        </button>
        <button
          onClick={() => onPageChange(currentPage + 1)}
          disabled={currentPage === totalPages}
          className="px-3 py-1 text-sm border rounded hover:bg-gray-50 disabled:opacity-50"
        >
          Next
        </button>
      </div>
    </div>
  </div>
);

const SortableHeader = ({ label, sortKey, currentSort, onSort }) => {
  const isActive = currentSort.key === sortKey;
  return (
    <th 
      className="p-2 border text-left cursor-pointer hover:bg-gray-50"
      onClick={() => onSort(sortKey)}
    >
      <div className="flex items-center space-x-1">
        <span>{label}</span>
        <div className="flex flex-col">
          <ChevronUp className={`h-3 w-3 ${isActive && currentSort.direction === 'asc' ? 'text-blue-600' : 'text-gray-400'}`} />
          <ChevronDown className={`h-3 w-3 ${isActive && currentSort.direction === 'desc' ? 'text-blue-600' : 'text-gray-400'}`} />
        </div>
      </div>
    </th>
  );
};

const TurnoverReports = () => {
  const [currentPage, setCurrentPage] = useState(1);
  const [sort, setSort] = useState({ key: 'site', direction: 'asc' });
  const [filters, setFilters] = useState({
    search: '',
    site: 'all'
  });

  // Site data
  const siteServiceData = [
    { 
      site: 'MARH IR',
      service: 'Vascular Surgery',
      records: 463,
      avgTurnover: 46,
      surgeonTurnover: 22,
      avgTurnoverSameSurgeon: 22
    },
    {
      site: 'MARH OR',
      service: 'Bariatrics',
      records: 192,
      avgTurnover: 79,
      surgeonTurnover: 43,
      avgTurnoverSameSurgeon: 43
    },
    {
      site: 'MARH OR',
      service: 'Colon and Rectal Surgery',
      records: 205,
      avgTurnover: 81,
      surgeonTurnover: 44,
      avgTurnoverSameSurgeon: 44
    },
    {
      site: 'MARH OR',
      service: 'Electrophysiology',
      records: 4,
      avgTurnover: 79,
      surgeonTurnover: 42,
      avgTurnoverSameSurgeon: 42
    },
    {
      site: 'MARH OR',
      service: 'Gastroenterology',
      records: 2,
      avgTurnover: 64,
      surgeonTurnover: 22,
      avgTurnoverSameSurgeon: 22
    },
    {
      site: 'MARH OR',
      service: 'General Surgery',
      records: 916,
      avgTurnover: 79,
      surgeonTurnover: 44,
      avgTurnoverSameSurgeon: 44
    },
    {
      site: 'MARH OR',
      service: 'Obstetrics and Gynecology',
      records: 1,
      avgTurnover: 90,
      surgeonTurnover: null,
      avgTurnoverSameSurgeon: null
    },
    {
      site: 'MARH OR',
      service: 'Oral Surgery',
      records: 3,
      avgTurnover: 100,
      surgeonTurnover: null,
      avgTurnoverSameSurgeon: null
    },
    {
      site: 'MARH OR',
      service: 'Otolaryngology',
      records: 4,
      avgTurnover: 79,
      surgeonTurnover: 42,
      avgTurnoverSameSurgeon: 42
    },
    {
      site: 'MARH OR',
      service: 'Podiatry',
      records: 36,
      avgTurnover: 73,
      surgeonTurnover: 35,
      avgTurnoverSameSurgeon: 35
    },
    {
      site: 'MARH OR',
      service: 'Pulmonary Disease',
      records: 4,
      avgTurnover: 73,
      surgeonTurnover: null,
      avgTurnoverSameSurgeon: null
    },
    {
      site: 'MARH OR',
      service: 'Thoracic Surgery',
      records: 169,
      avgTurnover: 97,
      surgeonTurnover: 53,
      avgTurnoverSameSurgeon: 53
    },
    {
      site: 'MARH OR',
      service: 'Urology',
      records: 1223,
      avgTurnover: 63,
      surgeonTurnover: 33,
      avgTurnoverSameSurgeon: 33
    },
    {
      site: 'MARH OR',
      service: 'Vascular Surgery',
      records: 1026,
      avgTurnover: 73,
      surgeonTurnover: 40,
      avgTurnoverSameSurgeon: 40
    }
  ];

  // Trend data
  const trendData = [
    { month: 'Jan 23', marhIR: 16.5, marhOR: 35.0 },
    { month: 'Mar 23', marhIR: 15.0, marhOR: 38.0 },
    { month: 'May 23', marhIR: 22.0, marhOR: 37.0 },
    { month: 'Jul 23', marhIR: 17.0, marhOR: 37.0 },
    { month: 'Sep 23', marhIR: 15.0, marhOR: 36.0 },
    { month: 'Nov 23', marhIR: 22.0, marhOR: 37.0 },
    { month: 'Jan 24', marhIR: 15.0, marhOR: 36.0 },
    { month: 'Mar 24', marhIR: 17.0, marhOR: 37.0 },
    { month: 'May 24', marhIR: 15.0, marhOR: 38.0 }
  ];

  // Tandem Room data
  const tandemRoomData = [
    {
      surgeon: 'YOUSSEF, NASSER I',
      service: 'Transplant Surgery',
      site: 'OLLH OR',
      records: 309,
      avgTurnover: 42.8,
      multiRoomTurnover: 5.6,
      medianMultiRoomTurnover: 5.0
    },
    {
      surgeon: 'MCMILLAN, SEAN',
      service: 'Orthopaedic Surgery',
      site: 'MEMH MHAS OR',
      records: 74,
      avgTurnover: 24.0,
      multiRoomTurnover: -10.9,
      medianMultiRoomTurnover: -11.0
    },
    {
      surgeon: 'MCMILLAN, SEAN',
      service: 'Orthopaedic Surgery',
      site: 'MEMH OR',
      records: 133,
      avgTurnover: 21.9,
      multiRoomTurnover: -13.9,
      medianMultiRoomTurnover: -16.0
    },
    {
      surgeon: 'REID, JEREMY J',
      service: 'Orthopaedic Surgery',
      site: 'VORH JRI OR',
      records: 97,
      avgTurnover: 16.1,
      multiRoomTurnover: -31.2,
      medianMultiRoomTurnover: -38.0
    },
    {
      surgeon: 'KLINGENSTEIN, GREGORY G',
      service: 'Orthopaedic Surgery',
      site: 'VORH JRI OR',
      records: 96,
      avgTurnover: 17.4,
      multiRoomTurnover: -25.9,
      medianMultiRoomTurnover: -35.0
    },
    {
      surgeon: 'PORAT, MANNY D',
      service: 'Orthopaedic Surgery',
      site: 'VORH JRI OR',
      records: 95,
      avgTurnover: 27.2,
      multiRoomTurnover: -9.1,
      medianMultiRoomTurnover: -14.0
    },
    {
      surgeon: 'JAIN, RAJESH K',
      service: 'Orthopaedic Surgery',
      site: 'VORH JRI OR',
      records: 90,
      avgTurnover: 21.2,
      multiRoomTurnover: -19.9,
      medianMultiRoomTurnover: -24.5
    },
    {
      surgeon: 'SCHOIFET, SCOTT DAVID',
      service: 'Orthopaedic Surgery',
      site: 'VORH JRI OR',
      records: 78,
      avgTurnover: 10.1,
      multiRoomTurnover: -28.0,
      medianMultiRoomTurnover: -33.5
    },
    {
      surgeon: 'HAYDEL, CHRISTOPHER L',
      service: 'Orthopaedic Surgery',
      site: 'VORH JRI OR',
      records: 61,
      avgTurnover: 65.3,
      multiRoomTurnover: 25.1,
      medianMultiRoomTurnover: 26.0
    },
    {
      surgeon: 'BUTANI, RAJEN P.',
      service: 'Urology',
      site: 'OLLH OR',
      records: 47,
      avgTurnover: 22.5,
      multiRoomTurnover: -19.1,
      medianMultiRoomTurnover: -20.0
    },
    {
      surgeon: 'KRISHNAN, JAYRAM',
      service: 'Urology',
      site: 'OLLH OR',
      records: 37,
      avgTurnover: 37.6,
      multiRoomTurnover: -6.0,
      medianMultiRoomTurnover: -5.0
    },
    {
      surgeon: 'ZARETSKY, CRAIG',
      service: 'General Surgery',
      site: 'VORH OR',
      records: 34,
      avgTurnover: 35.6,
      multiRoomTurnover: 3.8,
      medianMultiRoomTurnover: 0.5
    },
    {
      surgeon: 'NGUYEN, KHOA M.',
      service: 'Orthopaedic Surgery',
      site: 'MEMH OR',
      records: 42,
      avgTurnover: 51.5,
      multiRoomTurnover: 13.5,
      medianMultiRoomTurnover: 18.5
    },
    {
      surgeon: 'CHOUDHRI, OMAR A',
      service: 'Neurosurgery',
      site: 'OLLH OR',
      records: 37,
      avgTurnover: 57.9,
      multiRoomTurnover: -18.6,
      medianMultiRoomTurnover: -22.0
    },
    {
      surgeon: 'ANDREW, CONSTANTINE T',
      service: 'Vascular Surgery',
      site: 'OLLH OR',
      records: 33,
      avgTurnover: 40.9,
      multiRoomTurnover: -3.9,
      medianMultiRoomTurnover: 8.0
    },
    {
      surgeon: 'PERZIN, ADAM DEAN',
      service: 'Urology',
      site: 'MEMH OR',
      records: 33,
      avgTurnover: 63.0,
      multiRoomTurnover: 35.0,
      medianMultiRoomTurnover: 33.0
    },
    {
      surgeon: 'WEISS, BRIAN E',
      service: 'Urology',
      site: 'MEMH OR',
      records: 29,
      avgTurnover: 69.6,
      multiRoomTurnover: 38.6,
      medianMultiRoomTurnover: 36.0
    },
    {
      surgeon: 'PARSI, SRIKANTH',
      service: 'General Surgery',
      site: 'VORH OR',
      records: 20,
      avgTurnover: 68.9,
      multiRoomTurnover: 37.3,
      medianMultiRoomTurnover: 36.0
    }
  ];

  const handleSort = (key) => {
    setSort(prevSort => ({
      key,
      direction: prevSort.key === key && prevSort.direction === 'asc' ? 'desc' : 'asc'
    }));
    setCurrentPage(1);
  };

  const handleFilterChange = (key, value) => {
    setFilters(prev => ({ ...prev, [key]: value }));
    setCurrentPage(1);
  };

  const filterAndSortData = (data) => {
    let filteredData = [...data];

    // Apply filters
    if (filters.search) {
      const searchLower = filters.search.toLowerCase();
      filteredData = filteredData.filter(item => 
        item.site.toLowerCase().includes(searchLower) ||
        item.service.toLowerCase().includes(searchLower)
      );
    }

    if (filters.site !== 'all') {
      filteredData = filteredData.filter(item => item.site === filters.site);
    }

    // Apply sorting
    filteredData.sort((a, b) => {
      const aValue = a[sort.key];
      const bValue = b[sort.key];
      
      if (typeof aValue === 'number') {
        return sort.direction === 'asc' ? aValue - bValue : bValue - aValue;
      }
      
      return sort.direction === 'asc' 
        ? aValue.localeCompare(bValue)
        : bValue.localeCompare(aValue);
    });

    return filteredData;
  };

  const getPaginatedData = (data) => {
    const startIndex = (currentPage - 1) * ITEMS_PER_PAGE;
    return data.slice(startIndex, startIndex + ITEMS_PER_PAGE);
  };

  const CustomTooltip = ({ active, payload, label }) => {
    if (!active || !payload || !payload.length) return null;
    
    return (
      <div className="bg-white p-4 border rounded shadow">
        <p className="font-medium text-gray-900">{label}</p>
        {payload.map((entry, index) => (
          <p key={index} style={{ color: entry.color }}>
            {entry.name}: {entry.value} minutes
          </p>
        ))}
      </div>
    );
  };

  return (
    <div className="space-y-8">
      <Card>
        <CardHeader>
          <CardTitle>Turnover Time by Site and Service</CardTitle>
        </CardHeader>
        <CardContent>
          <FilterControls filters={filters} onFilterChange={handleFilterChange} />
          <div className="overflow-x-auto">
            <table className="w-full border-collapse">
              <thead>
                <tr>
                  <SortableHeader label="Site" sortKey="site" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Service" sortKey="service" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Records" sortKey="records" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Avg Turnover" sortKey="avgTurnover" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Surgeon Turnover" sortKey="surgeonTurnover" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Same Surgeon" sortKey="avgTurnoverSameSurgeon" currentSort={sort} onSort={handleSort} />
                </tr>
              </thead>
              <tbody>
                {getPaginatedData(filterAndSortData(siteServiceData)).map((row, idx) => (
                  <tr key={idx} className="hover:bg-gray-50">
                    <td className="p-2 border">{row.site}</td>
                    <td className="p-2 border">{row.service}</td>
                    <td className="p-2 border text-center">{row.records}</td>
                    <td className="p-2 border text-center">{row.avgTurnover} min</td>
                    <td className="p-2 border text-center">
                      {row.surgeonTurnover ? `${row.surgeonTurnover} min` : '-'}
                    </td>
                    <td className="p-2 border text-center">
                      {row.avgTurnoverSameSurgeon ? `${row.avgTurnoverSameSurgeon} min` : '-'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <TablePagination
              currentPage={currentPage}
              totalPages={Math.ceil(filterAndSortData(siteServiceData).length / ITEMS_PER_PAGE)}
              onPageChange={setCurrentPage}
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Median Turnover Times Trend</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="h-96">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={trendData} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="month" />
                <YAxis domain={[0, 40]} />
                <Tooltip content={<CustomTooltip />} />
                <Legend />
                <Line 
                  type="monotone" 
                  dataKey="marhIR" 
                  name="MARH IR" 
                  stroke="#2563eb" 
                  strokeWidth={2}
                  dot={{ fill: '#2563eb' }}
                />
                <Line 
                  type="monotone" 
                  dataKey="marhOR" 
                  name="MARH OR" 
                  stroke="#16a34a" 
                  strokeWidth={2}
                  dot={{ fill: '#16a34a' }}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Multi Room (Tandem Room) Turnover Analysis</CardTitle>
        </CardHeader>
        <CardContent>
          <FilterControls filters={filters} onFilterChange={handleFilterChange} />
          <div className="overflow-x-auto">
            <table className="w-full border-collapse">
              <thead>
                <tr>
                  <SortableHeader label="Surgeon" sortKey="surgeon" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Service" sortKey="service" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Site" sortKey="site" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Records" sortKey="records" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Avg Turnover" sortKey="avgTurnover" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Multi Room Turnover" sortKey="multiRoomTurnover" currentSort={sort} onSort={handleSort} />
                  <SortableHeader label="Median Multi Room" sortKey="medianMultiRoomTurnover" currentSort={sort} onSort={handleSort} />
                </tr>
              </thead>
              <tbody>
                {getPaginatedData(filterAndSortData(tandemRoomData)).map((row, idx) => (
                  <tr key={idx} className={`${row.multiRoomTurnover < 0 ? 'bg-green-50' : ''} hover:bg-gray-50/80`}>
                    <td className="p-2 border">{row.surgeon}</td>
                    <td className="p-2 border">{row.service}</td>
                    <td className="p-2 border">{row.site}</td>
                    <td className="p-2 border text-center">{row.records}</td>
                    <td className="p-2 border text-center">{row.avgTurnover.toFixed(1)} min</td>
                    <td className="p-2 border text-center">{row.multiRoomTurnover.toFixed(1)} min</td>
                    <td className="p-2 border text-center">{row.medianMultiRoomTurnover.toFixed(1)} min</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <TablePagination
              currentPage={currentPage}
              totalPages={Math.ceil(filterAndSortData(tandemRoomData).length / ITEMS_PER_PAGE)}
              onPageChange={setCurrentPage}
            />
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default TurnoverReports;
