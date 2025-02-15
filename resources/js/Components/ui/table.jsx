import React from 'react';

export const Table = ({ children, ...props }) => (
  <table {...props}>
    {children}
  </table>
);

export const TableHead = ({ children, ...props }) => (
  <thead {...props}>
    {children}
  </thead>
);

export const TableBody = ({ children, ...props }) => (
  <tbody {...props}>
    {children}
  </tbody>
);

export const TableRow = ({ children, ...props }) => (
  <tr {...props}>
    {children}
  </tr>
);

export const TableHeader = ({ children, ...props }) => (
  <th {...props}>
    {children}
  </th>
);

export const TableCell = ({ children, ...props }) => (
  <td {...props}>
    {children}
  </td>
);
