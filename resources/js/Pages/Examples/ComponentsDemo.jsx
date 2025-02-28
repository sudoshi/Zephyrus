import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { FlowbiteThemeProvider, NivoThemeProvider } from '@/Components/ui';
import { Card, Button, Tabs } from '@/Components/ui/flowbite';
import { BarChart, LineChart, PieChart } from '@/Components/ui/charts';

export default function ComponentsDemo({ auth }) {
  // Sample data for charts
  const barData = [
    { name: 'Jan', value1: 111, value2: 157 },
    { name: 'Feb', value1: 157, value2: 129 },
    { name: 'Mar', value1: 129, value2: 150 },
    { name: 'Apr', value1: 150, value2: 134 },
    { name: 'May', value1: 134, value2: 189 },
    { name: 'Jun', value1: 189, value2: 176 },
  ];

  const pieData = PieChart.formatData([
    { category: 'Category A', count: 111 },
    { category: 'Category B', count: 157 },
    { category: 'Category C', count: 129 },
    { category: 'Category D', count: 150 },
    { category: 'Category E', count: 134 },
  ], 'category', 'count');

  const lineData = LineChart.formatData(
    [
      { month: 'Jan', value1: 111, value2: 157 },
      { month: 'Feb', value1: 157, value2: 129 },
      { month: 'Mar', value1: 129, value2: 150 },
      { month: 'Apr', value1: 150, value2: 134 },
      { month: 'May', value1: 134, value2: 189 },
      { month: 'Jun', value1: 189, value2: 176 },
    ],
    'month',
    ['value1', 'value2'],
    ['Series 1', 'Series 2']
  );

  return (
    <AuthenticatedLayout
      user={auth.user}
      header={<h2 className="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Components Demo</h2>}
    >
      <Head title="Components Demo" />

      {/* Wrap the content with the theme providers */}
      <FlowbiteThemeProvider>
        <NivoThemeProvider>
          <div className="py-6">
            <div className="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
              <div className="bg-healthcare-surface-dark overflow-hidden shadow-sm sm:rounded-lg">
                <div className="p-6 text-healthcare-text-primary-dark">
                  <h1 className="text-2xl font-bold mb-6">UI Components Demo</h1>
                  
                  {/* Button Examples */}
                  <section className="mb-8">
                    <h2 className="text-xl font-semibold mb-4">Button Examples</h2>
                    <div className="flex flex-wrap gap-4">
                      <Button color="primary">Primary Button</Button>
                      <Button color="success">Success Button</Button>
                      <Button color="warning">Warning Button</Button>
                      <Button color="critical">Critical Button</Button>
                      <Button color="purple">Purple Button</Button>
                      <Button color="teal">Teal Button</Button>
                    </div>
                    <div className="flex flex-wrap gap-4 mt-4">
                      <Button color="primary" outline={true}>Primary Outline</Button>
                      <Button color="success" outline={true}>Success Outline</Button>
                      <Button color="warning" outline={true}>Warning Outline</Button>
                      <Button color="critical" outline={true}>Critical Outline</Button>
                    </div>
                  </section>
                  
                  {/* Card Examples */}
                  <section className="mb-8">
                    <h2 className="text-xl font-semibold mb-4">Card Examples</h2>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                      <Card title="Basic Card">
                        <p>This is a basic card with a title and content.</p>
                      </Card>
                      <Card title="Card with Footer" footer={<Button color="primary">Action</Button>}>
                        <p>This card has a footer with an action button.</p>
                      </Card>
                      <Card>
                        <Card.Header>
                          <h3 className="text-lg font-semibold">Custom Header</h3>
                          <p className="text-sm text-healthcare-text-secondary-dark">Subheading</p>
                        </Card.Header>
                        <Card.Body>
                          <p>This card has a custom header and body.</p>
                        </Card.Body>
                        <Card.Footer>
                          <div className="flex justify-between">
                            <Button color="primary" outline={true}>Cancel</Button>
                            <Button color="primary">Save</Button>
                          </div>
                        </Card.Footer>
                      </Card>
                    </div>
                  </section>
                  
                  {/* Tabs Example */}
                  <section className="mb-8">
                    <h2 className="text-xl font-semibold mb-4">Tabs Example</h2>
                    <Card className="mb-6">
                      <h5 className="text-xl font-bold mb-4">Tabs</h5>
                      <Tabs style={{ base: "underline" }}>
                        <Tabs.Item title="Tab 1">
                          <Card>
                            <p>Content for Tab 1</p>
                            <p className="mt-2">You can put any content here.</p>
                          </Card>
                        </Tabs.Item>
                        <Tabs.Item title="Tab 2">
                          <Card>
                            <p>Content for Tab 2</p>
                            <p className="mt-2">Different content for the second tab.</p>
                          </Card>
                        </Tabs.Item>
                        <Tabs.Item title="Tab 3">
                          <Card>
                            <p>Content for Tab 3</p>
                            <p className="mt-2">And even more content for the third tab.</p>
                          </Card>
                        </Tabs.Item>
                      </Tabs>
                    </Card>
                  </section>
                  
                  {/* Chart Examples */}
                  <section>
                    <h2 className="text-xl font-semibold mb-4">Chart Examples</h2>
                    <Card>
                      <h5 className="text-xl font-bold mb-4">Charts</h5>
                      <Tabs style={{ base: "underline" }}>
                        <Tabs.Item title="Bar Chart">
                          <Card title="Bar Chart Example">
                            <BarChart 
                              data={barData} 
                              keys={['value1', 'value2']} 
                              indexBy="name"
                              colorScheme="primary"
                            />
                          </Card>
                        </Tabs.Item>
                        <Tabs.Item title="Line Chart">
                          <Card title="Line Chart Example">
                            <LineChart 
                              data={lineData} 
                              colorScheme="mixed"
                              enableArea={true}
                              curve="monotoneX"
                            />
                          </Card>
                        </Tabs.Item>
                        <Tabs.Item title="Pie Chart">
                          <Card title="Pie Chart Example">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                              <div>
                                <h3 className="text-lg font-semibold mb-2">Regular Pie Chart</h3>
                                <PieChart 
                                  data={pieData} 
                                  colorScheme="mixed"
                                />
                              </div>
                              <div>
                                <h3 className="text-lg font-semibold mb-2">Donut Chart</h3>
                                <PieChart 
                                  data={pieData} 
                                  colorScheme="primary"
                                  innerRadius={0.6}
                                />
                              </div>
                            </div>
                          </Card>
                        </Tabs.Item>
                      </Tabs>
                    </Card>
                  </section>
                </div>
              </div>
            </div>
          </div>
        </NivoThemeProvider>
      </FlowbiteThemeProvider>
    </AuthenticatedLayout>
  );
}
